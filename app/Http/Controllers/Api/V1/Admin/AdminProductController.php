<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\ProductPrice;
use App\Models\ProductProviderSlot;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminProductController extends Controller
{
    public function index()
    {
        /** @var LengthAwarePaginator $p */
        $p = Product::query()
            ->with([
                'category',
                'prices.packages',
                'eligibleIntegrations',
                'providerSlots.integration',
            ])
            ->orderBy('sort_order')
            ->paginate(50);

        $p->setCollection(
            $p->getCollection()->map(fn (Product $product) => $this->transformCatalogRow($product))
        );

        return $p;
    }

    public function show($id)
    {
        $product = Product::with([
            'category',
            'prices.packages',
            'eligibleIntegrations',
            'providerSlots.integration',
        ])->findOrFail($id);

        return $this->transformCatalogRow($product, true);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'category_id' => ['nullable','integer','exists:categories,id'],
            'product_type' => ['required','in:fixed_package,flexible_quantity'],
            'name_ar' => ['required','string','max:255'],
            'name_tr' => ['nullable','string','max:255'],
            'name_en' => ['nullable','string','max:255'],
            'description_ar' => ['nullable','string'],
            'description_tr' => ['nullable','string'],
            'description_en' => ['nullable','string'],
            'image_url' => ['nullable','string','max:2048'],
            'is_active' => ['nullable','boolean'],
            'is_featured' => ['nullable','boolean'],
            'sort_order' => ['nullable','integer'],
            'currency_mode' => ['nullable','in:TRY,USD'],

            // fulfillment fields (legacy)
            'fulfillment_type' => ['nullable','string','max:50'],
            'provider_code' => ['nullable','string','max:100'],
            'fulfillment_config' => ['nullable','array'],

            // ✅ Phase 5: Providers
            'eligible_provider_integration_ids' => ['nullable','array'],
            'eligible_provider_integration_ids.*' => ['integer','distinct','exists:provider_integrations,id'],

            // Example: [{slot:1, provider_integration_id: 3}, {slot:2, provider_integration_id: 4}]
            'provider_slots' => ['nullable','array'],
            'provider_slots.*.slot' => ['required_with:provider_slots','integer','in:1,2'],
            'provider_slots.*.provider_integration_id' => ['nullable','integer','exists:provider_integrations,id'],
            'provider_slots.*.override_config' => ['nullable','array'],
            'provider_slots.*.is_active' => ['nullable','boolean'],
        ]);

        // Enforce category purchase_mode (if set)
        if (!empty($data['category_id'])) {
            $cat = Category::query()->find((int) $data['category_id']);
            if ($cat && !empty($cat->purchase_mode) && isset($data['product_type'])) {
                if ((string) $cat->purchase_mode !== (string) $data['product_type']) {
                    throw ValidationException::withMessages([
                        'product_type' => [
                            'product_type must match category.purchase_mode (' . (string) $cat->purchase_mode . ')',
                        ],
                    ]);
                }
            }
        }

        return DB::transaction(function () use ($data) {

            $eligible = $data['eligible_provider_integration_ids'] ?? null;
            $slots = $data['provider_slots'] ?? null;

            unset($data['eligible_provider_integration_ids'], $data['provider_slots']);

            $p = Product::create($data);

            $this->syncProviders($p, $eligible, $slots, true);

            // Prevent activating incomplete products
            $shouldBeActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $p->is_active;
            $this->assertProductActivationReadiness($p, $shouldBeActive);

            $p = $p->fresh([
                'category',
                'prices.packages',
                'eligibleIntegrations',
                'providerSlots.integration',
            ]);

            return $this->transformCatalogRow($p, true);
        });
    }

    public function update(Request $r, $id)
    {
        $p = Product::findOrFail($id);

        $data = $r->validate([
            'category_id' => ['sometimes','nullable','integer','exists:categories,id'],
            'product_type' => ['sometimes','in:fixed_package,flexible_quantity'],
            'name_ar' => ['sometimes','string','max:255'],
            'name_tr' => ['sometimes','nullable','string','max:255'],
            'name_en' => ['sometimes','nullable','string','max:255'],
            'description_ar' => ['sometimes','nullable','string'],
            'description_tr' => ['sometimes','nullable','string'],
            'description_en' => ['sometimes','nullable','string'],
            'image_url' => ['sometimes','nullable','string','max:2048'],
            'is_active' => ['sometimes','boolean'],
            'is_featured' => ['sometimes','boolean'],
            'sort_order' => ['sometimes','integer'],
            'currency_mode' => ['sometimes','in:TRY,USD'],

            // legacy fulfillment fields
            'fulfillment_type' => ['sometimes','nullable','string','max:50'],
            'provider_code' => ['sometimes','nullable','string','max:100'],
            'fulfillment_config' => ['sometimes','nullable','array'],

            // ✅ Phase 5: Providers
            'eligible_provider_integration_ids' => ['sometimes','nullable','array'],
            'eligible_provider_integration_ids.*' => ['integer','distinct','exists:provider_integrations,id'],

            'provider_slots' => ['sometimes','nullable','array'],
            'provider_slots.*.slot' => ['required_with:provider_slots','integer','in:1,2'],
            'provider_slots.*.provider_integration_id' => ['nullable','integer','exists:provider_integrations,id'],
            'provider_slots.*.override_config' => ['nullable','array'],
            'provider_slots.*.is_active' => ['nullable','boolean'],
        ]);

        // Enforce category purchase_mode (if set) when category or product_type changes
        $newCategoryId = array_key_exists('category_id', $data) ? $data['category_id'] : $p->category_id;
        $newProductType = array_key_exists('product_type', $data) ? $data['product_type'] : $p->product_type;

        if (!empty($newCategoryId)) {
            $cat = Category::query()->find((int) $newCategoryId);
            if ($cat && !empty($cat->purchase_mode) && $newProductType) {
                if ((string) $cat->purchase_mode !== (string) $newProductType) {
                    throw ValidationException::withMessages([
                        'product_type' => [
                            'product_type must match category.purchase_mode (' . (string) $cat->purchase_mode . ')',
                        ],
                    ]);
                }
            }
        }

        return DB::transaction(function () use ($p, $data) {

            $eligible = array_key_exists('eligible_provider_integration_ids', $data)
                ? $data['eligible_provider_integration_ids']
                : null;

            $slots = array_key_exists('provider_slots', $data)
                ? $data['provider_slots']
                : null;

            unset($data['eligible_provider_integration_ids'], $data['provider_slots']);

            $p->update($data);

            $this->syncProviders($p, $eligible, $slots, false);

            $shouldBeActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $p->is_active;
            $this->assertProductActivationReadiness($p->fresh(), $shouldBeActive);

            $p = $p->fresh([
                'category',
                'prices.packages',
                'eligibleIntegrations',
                'providerSlots.integration',
            ]);

            return $this->transformCatalogRow($p, true);
        });
    }

    /**
     * PUT /admin/products/order
     * Body: {"ordered_ids": [3,1,2,...]}
     */
    public function updateOrder(Request $r)
    {
        $data = $r->validate([
            'ordered_ids' => ['required','array','min:1'],
            'ordered_ids.*' => ['integer','distinct','exists:products,id'],
        ]);

        $ids = array_values($data['ordered_ids']);

        DB::transaction(function () use ($ids) {
            foreach ($ids as $i => $id) {
                Product::query()
                    ->where('id', (int) $id)
                    ->update(['sort_order' => (int) $i]);
            }
        });

        return response()->json([
            'message' => 'Order updated',
            'count' => count($ids),
        ]);
    }

    /**
     * PUT /admin/products/bulk-provider
     * Body: {"product_ids": [1,2], "provider_integration_id": 5, "slot": 1}
     */
    public function bulkProvider(Request $r)
    {
        $data = $r->validate([
            'product_ids' => ['required','array','min:1'],
            'product_ids.*' => ['integer','distinct','exists:products,id'],
            'provider_integration_id' => ['required','integer','exists:provider_integrations,id'],
            'slot' => ['nullable','integer','in:1,2'],
        ]);

        $slot = (int) ($data['slot'] ?? 1);
        $integrationId = (int) $data['provider_integration_id'];
        $productIds = array_values($data['product_ids']);

        DB::transaction(function () use ($slot, $integrationId, $productIds) {

            foreach ($productIds as $pid) {
                $pid = (int) $pid;

                // Ensure eligibility (upsert)
                DB::table('product_provider_eligibles')->updateOrInsert(
                    ['product_id' => $pid, 'provider_integration_id' => $integrationId],
                    ['updated_at' => now(), 'created_at' => now()]
                );

                // Set slot
                ProductProviderSlot::query()->updateOrCreate(
                    ['product_id' => $pid, 'slot' => $slot],
                    [
                        'provider_integration_id' => $integrationId,
                        'is_active' => true,
                    ]
                );
            }
        });

        return response()->json([
            'message' => 'Bulk provider updated',
            'slot' => $slot,
            'provider_integration_id' => $integrationId,
            'count' => count($productIds),
        ]);
    }

    public function destroy($id)
    {
        $p = Product::findOrFail($id);
        $p->delete();
        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Sync eligible providers + slots.
     *
     * Rules:
     * - If eligible is provided (array|null), we sync. If not provided (null), we keep as-is.
     * - If slots is provided (array|null), we replace all slots (product_id) with the provided list.
     * - Any provider used in a slot is automatically added to eligible list.
     */
    private function syncProviders(Product $p, $eligibleIds, $slots, bool $isCreate): void
    {
        // Eligible providers
        if ($eligibleIds !== null) {
            $ids = is_array($eligibleIds) ? array_values(array_unique(array_map('intval', $eligibleIds))) : [];
            $p->eligibleIntegrations()->sync($ids);
        }

        // Provider slots
        if ($slots !== null) {
            $rows = is_array($slots) ? $slots : [];

            // Replace all
            ProductProviderSlot::query()->where('product_id', (int) $p->id)->delete();

            $slotUsedIds = [];

            foreach ($rows as $row) {
                if (!is_array($row)) continue;

                $slotNo = (int) ($row['slot'] ?? 0);
                if (!in_array($slotNo, [1,2], true)) continue;

                $integrationId = isset($row['provider_integration_id']) ? (int) $row['provider_integration_id'] : null;
                $isActive = array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true;

                $overrideConfig = $row['override_config'] ?? null;
                if ($overrideConfig !== null && !is_array($overrideConfig)) {
                    $overrideConfig = null;
                }

                ProductProviderSlot::query()->create([
                    'product_id' => (int) $p->id,
                    'slot' => $slotNo,
                    'provider_integration_id' => $integrationId,
                    'override_config' => $overrideConfig,
                    'is_active' => $isActive,
                ]);

                if ($integrationId) {
                    $slotUsedIds[] = $integrationId;
                }
            }

            // Ensure slot providers are eligible
            if (count($slotUsedIds) > 0) {
                $existing = $p->eligibleIntegrations()->pluck('provider_integrations.id')->all();
                $all = array_values(array_unique(array_merge($existing, $slotUsedIds)));
                $p->eligibleIntegrations()->sync($all);
            }
        }

        // Create default empty slots to simplify UI expectations
        if ($isCreate && $slots === null) {
            // optional: do nothing
        }
    }

    private function assertProductActivationReadiness(Product $product, bool $shouldBeActive): void
    {
        if (!$shouldBeActive) {
            return;
        }

        if ((string) $product->product_type === 'fixed_package') {
            $hasActivePackage = ProductPackage::query()
                ->whereHas('productPrice', function ($q) use ($product) {
                    $q->where('product_id', (int) $product->id)->where('is_active', true);
                })
                ->where('is_active', true)
                ->exists();

            if (!$hasActivePackage) {
                throw ValidationException::withMessages([
                    'is_active' => ['Cannot activate fixed_package product without active packages.'],
                ]);
            }

            return;
        }

        if ((string) $product->product_type === 'flexible_quantity') {
            $prices = ProductPrice::query()
                ->where('product_id', (int) $product->id)
                ->where('is_active', true)
                ->get();

            if ($prices->isEmpty()) {
                throw ValidationException::withMessages([
                    'is_active' => ['Cannot activate flexible_quantity product without active price rows.'],
                ]);
            }

            $errors = [];
            foreach ($prices as $price) {
                if ($price->min_qty === null || $price->max_qty === null || (int) $price->max_qty < (int) $price->min_qty) {
                    $errors[] = 'Price row #' . (int) $price->id . ' requires valid min_qty/max_qty.';
                    continue;
                }

                $hasDecimal = $price->unit_price_decimal !== null;
                $hasMinor = ((int) ($price->unit_price_minor ?? 0)) > 0;
                $isUsdMode = strtoupper((string) ($product->currency_mode ?? 'TRY')) === 'USD';
                $hasUsdSource = $isUsdMode && (
                    $price->unit_price_usd !== null
                    || $product->suggested_unit_usd !== null
                    || $product->cost_unit_usd !== null
                );

                if (!$hasDecimal && !$hasMinor && !$hasUsdSource) {
                    $errors[] = 'Price row #' . (int) $price->id . ' has no valid unit pricing source.';
                }
            }

            if (count($errors) > 0) {
                throw ValidationException::withMessages([
                    'is_active' => $errors,
                ]);
            }
        }
    }

    private function transformCatalogRow(Product $product, bool $withRawRelations = false): array
    {
        $slotMap = [];
        foreach ($product->providerSlots as $slot) {
            $slotNo = (int) ($slot->slot ?? 0);
            if (!in_array($slotNo, [1, 2], true)) {
                continue;
            }

            $slotMap[$slotNo] = [
                'slot' => $slotNo,
                'provider_integration_id' => $slot->provider_integration_id,
                'is_active' => (bool) $slot->is_active,
                'integration' => $slot->integration ? [
                    'id' => (int) $slot->integration->id,
                    'name' => (string) $slot->integration->name,
                    'template_code' => (string) $slot->integration->template_code,
                    'type' => (string) ($slot->integration->type ?? ''),
                    'is_active' => (bool) $slot->integration->is_active,
                ] : null,
            ];
        }

        [$costUsd, $suggestedUsd, $priceSummary] = $this->buildUsdSummary($product);

        $row = [
            'id' => (int) $product->id,
            'display_order' => (int) $product->sort_order,
            'product_type' => (string) $product->product_type,
            'status' => (bool) $product->is_active ? 'active' : 'inactive',
            'is_active' => (bool) $product->is_active,
            'is_featured' => (bool) $product->is_featured,
            'name' => [
                'ar' => $product->name_ar,
                'tr' => $product->name_tr,
                'en' => $product->name_en,
            ],
            'product_name' => (string) ($product->name_ar ?: $product->name_en ?: $product->name_tr ?: ('#'.$product->id)),
            'category' => $product->category ? [
                'id' => (int) $product->category->id,
                'name_ar' => (string) $product->category->name_ar,
                'name_tr' => (string) ($product->category->name_tr ?? ''),
                'name_en' => (string) ($product->category->name_en ?? ''),
            ] : null,
            'currency_mode' => (string) ($product->currency_mode ?? 'TRY'),
            'cost_usd' => $costUsd,
            'suggested_usd' => $suggestedUsd,
            'price_summary' => $priceSummary,
            'eligible_providers' => $product->eligibleIntegrations->map(function ($integration) {
                return [
                    'id' => (int) $integration->id,
                    'name' => (string) $integration->name,
                    'template_code' => (string) $integration->template_code,
                    'type' => (string) ($integration->type ?? ''),
                    'is_active' => (bool) $integration->is_active,
                ];
            })->values()->all(),
            'provider_slot_1' => $slotMap[1] ?? null,
            'provider_slot_2' => $slotMap[2] ?? null,
            'provider_slots' => [
                'slot1' => $slotMap[1] ?? null,
                'slot2' => $slotMap[2] ?? null,
            ],
            'created_at' => $product->created_at,
            'updated_at' => $product->updated_at,
        ];

        if ($withRawRelations) {
            $row['raw'] = [
                'prices' => $product->prices,
                'provider_slots' => $product->providerSlots,
            ];
        }

        return $row;
    }

    private function buildUsdSummary(Product $product): array
    {
        $cost = $product->cost_unit_usd;
        $suggested = $product->suggested_unit_usd;

        $activePackages = $product->prices
            ->flatMap(fn ($price) => $price->packages)
            ->filter(fn ($package) => (bool) $package->is_active)
            ->values();

        if ($cost === null) {
            $costValues = $activePackages
                ->pluck('cost_usd')
                ->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => (string) $v)
                ->unique()
                ->values();

            if ($costValues->count() === 1) {
                $cost = $costValues->first();
            } elseif ($costValues->count() > 1) {
                $cost = 'varies';
            }
        }

        if ($suggested === null) {
            $suggestedValues = $activePackages
                ->pluck('suggested_usd')
                ->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => (string) $v)
                ->unique()
                ->values();

            if ($suggestedValues->count() === 1) {
                $suggested = $suggestedValues->first();
            } elseif ($suggestedValues->count() > 1) {
                $suggested = 'varies';
            }
        }

        return [
            $cost,
            $suggested,
            [
                'cost' => $cost,
                'suggested' => $suggested,
            ],
        ];
    }
}
