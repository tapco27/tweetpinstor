<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductProviderSlot;
use App\Models\ProviderIntegration;
use App\Services\Providers\TweetPinApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7: Tweet-Pin Mapping helper.
 *
 * Goal: allow admin to map Tweet-Pin remote product IDs to local products/packages
 * via provider_slots.override_config (without editing JSON manually).
 *
 * Route:
 *  - PUT /api/v1/admin/products/{id}/tweetpin/mapping
 *
 * Supported body examples:
 *
 * Flexible quantity:
 * {
 *   "provider_integration_id": 8,
 *   "slot": 1,
 *   "provider_product_id": 46,
 *   "params_map": {"uid":"playerId"},
 *   "qty_values": {"min":"1000","max":"930000"},
 *   "sync_qty_to_product_prices": true
 * }
 *
 * Fixed packages:
 * {
 *   "provider_integration_id": 8,
 *   "slot": 1,
 *   "package_map": {"12": 18, "13": 365},
 *   "params_map": {"player_id":"playerId"}
 * }
 *
 * Fixed packages + amount-type provider (same remote id, qty differs per package):
 * {
 *   "provider_integration_id": 8,
 *   "slot": 1,
 *   "provider_product_id": 46,
 *   "package_qty_map": {"12": 50000, "13": 100000},
 *   "params_map": {"uid":"playerId"},
 *   "qty_values": {"min":"1000","max":"930000"}
 * }
 */
class AdminTweetPinMappingController extends Controller
{
    public function __construct(private TweetPinApiClient $tweetPin) {}

    public function update(Request $r, $id)
    {
        /** @var Product $product */
        $product = Product::query()->findOrFail((int) $id);

        $data = $r->validate([
            'provider_integration_id' => ['required','integer','exists:provider_integrations,id'],
            'slot' => ['nullable','integer','in:1,2'],

            // For flexible_quantity
            'provider_product_id' => ['nullable','integer','min:1'],

            // For fixed_package
            'package_map' => ['nullable','array'],
            'package_map.*' => ['integer','min:1'],

            // For fixed_package + amount-type provider
            'package_qty_map' => ['nullable','array'],
            'package_qty_map.*' => ['integer','min:1'],

            // Optional mapping: local metadata key => provider query key
            'params_map' => ['nullable','array'],
            'params_map.*' => ['string','max:100'],

            // Optional static params always sent to provider
            'static_params' => ['nullable','array'],

            // Optional qty_values constraint from provider API (null | array | {min,max})
            'qty_values' => ['nullable'],

            // Optional helpers
            'sync_qty_to_product_prices' => ['nullable','boolean'],
        ]);

        $slotNo = (int) ($data['slot'] ?? 1);
        $integration = $this->loadTweetPinIntegration((int) $data['provider_integration_id']);

        // Basic sanity
        if ((string) $product->product_type === 'fixed_package' && !empty($data['provider_product_id'])) {
            // Allow, but strongly recommend mapping at package level.
            // (No exception; admin may use a single remote id as a shortcut.)
        }

        if ((string) $product->product_type === 'flexible_quantity' && !empty($data['package_map'])) {
            throw ValidationException::withMessages([
                'package_map' => ['package_map is only valid for fixed_package products'],
            ]);
        }

        if ((string) $product->product_type === 'flexible_quantity' && !empty($data['package_qty_map'])) {
            throw ValidationException::withMessages([
                'package_qty_map' => ['package_qty_map is only valid for fixed_package products'],
            ]);
        }

        return DB::transaction(function () use ($product, $integration, $slotNo, $data) {

            /** @var ProductProviderSlot $slot */
            $slot = ProductProviderSlot::query()->firstOrNew([
                'product_id' => (int) $product->id,
                'slot' => (int) $slotNo,
            ]);

            $slot->provider_integration_id = (int) $integration->id;
            $slot->is_active = true;

            $cfg = is_array($slot->override_config ?? null) ? $slot->override_config : [];

            // provider_product_id (flexible) / global fallback
            if (!empty($data['provider_product_id'])) {
                $cfg['provider_product_id'] = (int) $data['provider_product_id'];
            }

            // package map (fixed)
            if (!empty($data['package_map']) && is_array($data['package_map'])) {
                $map = [];
                foreach ($data['package_map'] as $k => $v) {
                    $kk = is_string($k) ? trim($k) : (string) $k;
                    if ($kk === '') continue;
                    if (!is_numeric($v)) continue;
                    $map[$kk] = (int) $v;
                }
                $cfg['tweetpin_package_map'] = $map;
            }

            // package qty map (fixed + amount provider)
            if (!empty($data['package_qty_map']) && is_array($data['package_qty_map'])) {
                $qmap = [];
                foreach ($data['package_qty_map'] as $k => $v) {
                    $kk = is_string($k) ? trim($k) : (string) $k;
                    if ($kk === '') continue;
                    if (!is_numeric($v)) continue;
                    $vv = (int) $v;
                    if ($vv <= 0) continue;
                    $qmap[$kk] = $vv;
                }
                $cfg['tweetpin_package_qty_map'] = $qmap;
            }

            // params map
            if (!empty($data['params_map']) && is_array($data['params_map'])) {
                $pm = [];
                foreach ($data['params_map'] as $k => $v) {
                    if (!is_string($k) || trim($k) === '') continue;
                    if (!is_string($v) || trim($v) === '') continue;
                    $pm[trim($k)] = trim($v);
                }
                $cfg['params_map'] = $pm;
            }

            // static params
            if (!empty($data['static_params']) && is_array($data['static_params'])) {
                $sp = [];
                foreach ($data['static_params'] as $k => $v) {
                    if (!is_string($k) || trim($k) === '') continue;
                    if (is_scalar($v) || $v === null) {
                        $sp[trim($k)] = $v;
                    }
                }
                $cfg['static_params'] = $sp;
            }

            // qty_values constraint
            if (array_key_exists('qty_values', $data)) {
                $cfg['tweetpin_qty_values'] = $data['qty_values'];

                // Optional: sync min/max into product_prices for better UX (flexible only)
                $sync = (bool) ($data['sync_qty_to_product_prices'] ?? true);
                if ($sync && (string) $product->product_type === 'flexible_quantity') {
                    $mm = $this->extractMinMaxFromQtyValues($data['qty_values']);
                    if ($mm) {
                        ProductPrice::query()
                            ->where('product_id', (int) $product->id)
                            ->update([
                                'min_qty' => $mm['min'],
                                'max_qty' => $mm['max'],
                            ]);
                    }
                }
            }

            $slot->override_config = $cfg;
            $slot->save();

            // Ensure eligibility
            $product->eligibleIntegrations()->syncWithoutDetaching([(int) $integration->id]);

            return $product->fresh([
                'category',
                'prices.packages',
                'eligibleIntegrations',
                'providerSlots.integration',
            ]);
        });
    }

    private function loadTweetPinIntegration(int $id): ProviderIntegration
    {
        /** @var ProviderIntegration $integration */
        $integration = ProviderIntegration::query()->findOrFail($id);

        $code = (string) ($integration->template_code ?? '');
        if (!in_array($code, ['tweet_pin', 'tweetpin', 'tweet-pin'], true)) {
            throw ValidationException::withMessages([
                'provider_integration_id' => ['Integration is not Tweet-Pin template'],
            ]);
        }

        return $integration;
    }

    /**
     * qty_values formats:
     * - null -> (1,1)
     * - ["110","150"] -> (110,150)
     * - {min:"1000", max:"930000"}
     */
    private function extractMinMaxFromQtyValues($qtyValues): ?array
    {
        if ($qtyValues === null) {
            return ['min' => 1, 'max' => 1];
        }

        if (is_array($qtyValues)) {
            // list
            if (array_is_list($qtyValues)) {
                $nums = [];
                foreach ($qtyValues as $v) {
                    if (is_numeric($v)) {
                        $nums[] = (int) $v;
                    }
                }
                if (count($nums) === 0) return null;
                sort($nums);
                return ['min' => (int) $nums[0], 'max' => (int) $nums[count($nums) - 1]];
            }

            // object
            $min = $qtyValues['min'] ?? null;
            $max = $qtyValues['max'] ?? null;
            if (is_numeric($min) && is_numeric($max)) {
                return ['min' => (int) $min, 'max' => (int) $max];
            }
        }

        return null;
    }
}
