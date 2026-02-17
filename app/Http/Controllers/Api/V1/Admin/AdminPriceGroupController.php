<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PriceGroup;
use App\Models\ProductPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminPriceGroupController extends Controller
{
    public function index()
    {
        return PriceGroup::query()->orderBy('id')->paginate(50);
    }

    public function show($id)
    {
        return PriceGroup::findOrFail($id);
    }

    /**
     * POST /admin/price-groups
     *
     * Body example:
     * {
     *   "name": "Reseller A",
     *   "code": "reseller_a", // optional
     *   "clone_from_id": 1,     // optional (default 1)
     *   "adjustment": {"type":"percent","value":10} // optional
     * }
     */
    public function store(Request $r)
    {
        $data = $r->validate([
            'name' => ['required','string','max:100'],
            'code' => ['nullable','string','max:50','alpha_dash','unique:price_groups,code'],
            'is_active' => ['nullable','boolean'],
            'clone_from_id' => ['nullable','integer','exists:price_groups,id'],
            'clone_prices' => ['nullable','boolean'],
            'adjustment' => ['nullable','array'],
            'adjustment.type' => ['required_with:adjustment','in:percent,amount_minor'],
            'adjustment.value' => ['required_with:adjustment','numeric'],
        ]);

        $cloneFromId = (int) ($data['clone_from_id'] ?? PriceGroup::DEFAULT_ID);
        $clonePrices = array_key_exists('clone_prices', $data) ? (bool) $data['clone_prices'] : true;

        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $code = Str::slug($data['name']);
            if ($code === '') {
                $code = 'group_' . Str::lower(Str::random(6));
            }

            // ensure unique
            $base = $code;
            $i = 2;
            while (PriceGroup::query()->where('code', $code)->exists()) {
                $code = $base . '_' . $i;
                $i++;
                if ($i > 99) {
                    $code = $base . '_' . Str::lower(Str::random(4));
                    break;
                }
            }
        }

        $adjustment = $data['adjustment'] ?? null;

        return DB::transaction(function () use ($data, $cloneFromId, $clonePrices, $code, $adjustment) {

            // Safety: default group cannot be cloned-from missing
            if ($cloneFromId <= 0) {
                $cloneFromId = PriceGroup::DEFAULT_ID;
            }

            $pg = PriceGroup::create([
                'code' => $code,
                'name' => $data['name'],
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                'is_default' => false,
            ]);

            if ($clonePrices) {
                $this->clonePricesFromGroup($cloneFromId, (int) $pg->id, $adjustment);
            }

            $this->audit('price_group_create', $pg->id, [
                'clone_from_id' => $cloneFromId,
                'clone_prices' => $clonePrices,
                'adjustment' => $adjustment,
            ]);

            return $pg;
        });
    }

    public function update(Request $r, $id)
    {
        $pg = PriceGroup::findOrFail($id);

        if ((int) $pg->id === PriceGroup::DEFAULT_ID && $r->has('is_default')) {
            throw ValidationException::withMessages(['is_default' => ['Default group is fixed.']]);
        }

        $data = $r->validate([
            'name' => ['sometimes','string','max:100'],
            'code' => ['sometimes','string','max:50','alpha_dash','unique:price_groups,code,' . (int) $pg->id],
            'is_active' => ['sometimes','boolean'],
        ]);

        $old = $pg->toArray();
        $pg->update($data);

        $this->audit('price_group_update', (int) $pg->id, [
            'old' => $old,
            'new' => $pg->toArray(),
        ]);

        return $pg;
    }

    /**
     * DELETE /admin/price-groups/{id}
     * NOTE: We do NOT hard-delete to avoid breaking order_items foreign keys.
     */
    public function destroy($id)
    {
        $pg = PriceGroup::findOrFail($id);

        if ((int) $pg->id === PriceGroup::DEFAULT_ID) {
            return response()->json([
                'message' => 'Cannot delete default price group',
            ], 422);
        }

        $pg->is_active = false;
        $pg->save();

        $this->audit('price_group_deactivate', (int) $pg->id);

        return response()->json(['message' => 'Deactivated']);
    }

    private function clonePricesFromGroup(int $fromGroupId, int $toGroupId, ?array $adjustment = null): void
    {
        $prices = ProductPrice::query()
            ->where('price_group_id', $fromGroupId)
            ->with('packages')
            ->get();

        foreach ($prices as $pp) {
            $new = $pp->replicate(['id', 'created_at', 'updated_at']);
            $new->price_group_id = $toGroupId;

            if ($adjustment) {
                $new->unit_price_minor = $this->applyAdjustmentInt(
                    $pp->unit_price_minor,
                    (string) ($adjustment['type'] ?? ''),
                    $adjustment['value'] ?? 0
                );
            }

            $new->save();

            foreach ($pp->packages as $pkg) {
                $newPkg = $pkg->replicate(['id', 'created_at', 'updated_at']);
                $newPkg->product_price_id = $new->id;

                if ($adjustment) {
                    $newPkg->price_minor = $this->applyAdjustmentInt(
                        $pkg->price_minor,
                        (string) ($adjustment['type'] ?? ''),
                        $adjustment['value'] ?? 0
                    );
                }

                $newPkg->save();
            }
        }
    }

    private function applyAdjustmentInt($oldValue, string $type, $value)
    {
        if ($oldValue === null) {
            return null;
        }

        $old = (int) $oldValue;

        if ($type === 'percent') {
            $pct = (float) $value;
            $new = (int) round($old * (1.0 + ($pct / 100.0)));
            return max(0, $new);
        }

        if ($type === 'amount_minor') {
            $delta = (int) round((float) $value);
            return max(0, $old + $delta);
        }

        // Unknown -> no change
        return $old;
    }

    private function audit(string $action, int $entityId, array $meta = []): void
    {
        try {
            if (!class_exists(AuditLog::class)) {
                return;
            }

            $actorId = auth('api')->id();

            AuditLog::create([
                'actor_id' => $actorId,
                'action' => $action,
                'entity_type' => 'price_group',
                'entity_id' => $entityId,
                'meta' => $meta,
            ]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
