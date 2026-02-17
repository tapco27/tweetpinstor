<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductProviderSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminProductController extends Controller
{
    public function index()
    {
        return Product::query()
            ->with([
                'category',
                'prices.packages',
                'eligibleIntegrations',
                'providerSlots.integration',
            ])
            ->orderBy('sort_order')
            ->paginate(50);
    }

    public function show($id)
    {
        return Product::with([
            'category',
            'prices.packages',
            'eligibleIntegrations',
            'providerSlots.integration',
        ])->findOrFail($id);
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

            return $p->fresh([
                'category',
                'prices.packages',
                'eligibleIntegrations',
                'providerSlots.integration',
            ]);
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

            return $p->fresh([
                'category',
                'prices.packages',
                'eligibleIntegrations',
                'providerSlots.integration',
            ]);
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
}
