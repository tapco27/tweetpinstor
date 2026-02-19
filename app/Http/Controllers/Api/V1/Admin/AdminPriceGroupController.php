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
        $this->ensureDefaultGroupIntegrity();
        return PriceGroup::query()->orderBy('id')->paginate(50);
    }

    public function show($id)
    {
        $this->ensureDefaultGroupIntegrity();
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

            $this->ensureDefaultGroupIntegrity();

            return $pg;
        });
    }

    public function update(Request $r, $id)
    {
        $pg = PriceGroup::findOrFail($id);

        $data = $r->validate([
            'name' => ['sometimes','string','max:100'],
            'code' => ['sometimes','string','max:50','alpha_dash','unique:price_groups,code,' . (int) $pg->id],
            'is_active' => ['sometimes','boolean'],
        ]);

        if (array_key_exists('is_active', $data) && !$data['is_active'] && (bool) $pg->is_default) {
            throw ValidationException::withMessages([
                'is_active' => ['Cannot deactivate default price group before assigning a new default'],
            ]);
        }

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

        $data = request()->validate([
            'new_default_id' => ['nullable', 'integer', 'different:id', 'exists:price_groups,id'],
        ]);

        return DB::transaction(function () use ($pg, $data) {
            if ((bool) $pg->is_default) {
                $newDefaultId = isset($data['new_default_id']) ? (int) $data['new_default_id'] : null;
                if (!$newDefaultId) {
                    return response()->json([
                        'message' => 'Cannot deactivate default price group before assigning a new default',
                    ], 422);
                }

                $candidate = PriceGroup::query()->whereKey($newDefaultId)->lockForUpdate()->firstOrFail();
                if (!(bool) $candidate->is_active) {
                    return response()->json([
                        'message' => 'new_default_id must point to an active price group',
                    ], 422);
                }

                PriceGroup::query()->where('is_default', true)->update(['is_default' => false]);
                $candidate->is_default = true;
                $candidate->save();
            }

            $pg->is_active = false;
            $pg->is_default = false;
            $pg->save();

            $this->ensureDefaultGroupIntegrity();

            $this->audit('price_group_deactivate', (int) $pg->id, [
                'new_default_id' => $data['new_default_id'] ?? null,
            ]);

            return response()->json(['message' => 'Deactivated']);
        });
    }

    /**
     * POST /admin/price-groups/{id}/set-default
     */
    public function setDefault($id)
    {
        return DB::transaction(function () use ($id) {
            $pg = PriceGroup::query()->whereKey((int) $id)->lockForUpdate()->firstOrFail();

            if (!(bool) $pg->is_active) {
                return response()->json([
                    'message' => 'Cannot set inactive price group as default',
                ], 422);
            }

            $oldDefaultId = PriceGroup::query()->where('is_default', true)->value('id');

            PriceGroup::query()->where('is_default', true)->update(['is_default' => false]);
            $pg->is_default = true;
            $pg->save();

            $this->audit('price_group_set_default', (int) $pg->id, [
                'old_default_id' => $oldDefaultId,
                'new_default_id' => (int) $pg->id,
            ]);

            return $pg;
        });
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
                'actor_type' => $actorId ? 'App\Models\User' : null,
                'actor_id' => $actorId,
                'auditable_type' => 'App\Models\PriceGroup',
                'auditable_id' => $entityId,
                'action' => $action,
                'old_values' => null,
                'new_values' => null,
                'meta' => $meta,
            ]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Data-healing safety for historical bad data:
     * - if no default exists, pick first active group by id
     * - ensure default is always active
     */
    private function ensureDefaultGroupIntegrity(): void
    {
        try {
            $default = PriceGroup::query()->where('is_default', true)->orderBy('id')->first();

            if ($default) {
                if (!(bool) $default->is_active) {
                    $fallback = PriceGroup::query()
                        ->where('is_active', true)
                        ->where('id', '!=', (int) $default->id)
                        ->orderBy('id')
                        ->first();

                    if ($fallback) {
                        PriceGroup::query()->where('is_default', true)->update(['is_default' => false]);
                        $fallback->is_default = true;
                        $fallback->save();
                    }
                }
                return;
            }

            $fallback = PriceGroup::query()->where('is_active', true)->orderBy('id')->first();
            if ($fallback) {
                $fallback->is_default = true;
                $fallback->save();
            }
        } catch (\Throwable $e) {
            // non-blocking safeguard
        }
    }
}
