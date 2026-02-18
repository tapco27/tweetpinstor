<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\FxRate;
use App\Models\PriceGroup;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\ProductPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminPricingController extends Controller
{
    public function grid(Request $request)
    {
        if ($request->filled('currency')) {
            $request->merge(['currency' => strtoupper((string) $request->input('currency'))]);
        }

        $data = $request->validate([
            'price_group_id' => ['nullable', 'integer', 'min:1', 'exists:price_groups,id'],
            'currency' => ['nullable', 'string', Rule::in(['TRY', 'SYP'])],
            'product_ids' => ['nullable', 'array', 'max:200'],
            'product_ids.*' => ['integer', 'min:1', 'exists:products,id'],
        ]);

        $q = ProductPrice::query()
            ->with([
                'product:id,name_ar,currency_mode,suggested_unit_usd,cost_unit_usd',
                'priceGroup:id,code,name',
                'packages',
            ])
            ->orderBy('product_id')
            ->orderBy('currency')
            ->orderBy('price_group_id');

        if (isset($data['price_group_id'])) {
            $q->where('price_group_id', (int) $data['price_group_id']);
        }
        if (isset($data['currency'])) {
            $q->where('currency', $data['currency']);
        }
        if (!empty($data['product_ids'])) {
            $q->whereIn('product_id', $data['product_ids']);
        }

        $rows = $q->get();

        return response()->json($this->payload([
            'filters' => [
                'price_group_id' => $data['price_group_id'] ?? null,
                'currency' => $data['currency'] ?? null,
            ],
            'rows' => $rows,
        ], 0, []));
    }

    public function recalculateUsd(Request $request)
    {
        if ($request->filled('currency')) {
            $request->merge(['currency' => strtoupper((string) $request->input('currency'))]);
        }

        $data = $request->validate([
            'scope' => ['required', Rule::in(['products', 'packages', 'all'])],
            'price_group_id' => ['nullable', 'integer', 'min:1', 'exists:price_groups,id'],
            'currency' => ['nullable', 'string', Rule::in(['TRY', 'SYP'])],
            'product_ids' => ['nullable', 'array', 'max:200'],
            'product_ids.*' => ['integer', 'min:1', 'exists:products,id'],
        ]);

        $rates = FxRate::query()
            ->whereIn('pair', ['USD_TRY', 'USD_SYP'])
            ->get()
            ->keyBy('pair');

        $errors = [];
        $affected = 0;

        DB::transaction(function () use ($data, $rates, &$errors, &$affected) {
            // PRODUCTS (ProductPrice)
            if (in_array($data['scope'], ['products', 'all'], true)) {
                $prices = ProductPrice::query()
                    ->with(['product:id,suggested_unit_usd,cost_unit_usd']);

                if (isset($data['price_group_id'])) {
                    $prices->where('price_group_id', (int) $data['price_group_id']);
                }
                if (isset($data['currency'])) {
                    $prices->where('currency', $data['currency']);
                }
                if (!empty($data['product_ids'])) {
                    $prices->whereIn('product_id', $data['product_ids']);
                }

                $prices->orderBy('id')->chunkById(500, function ($chunk) use ($rates, &$errors, &$affected) {
                    foreach ($chunk as $price) {
                        $usd = $price->product?->suggested_unit_usd ?? $price->product?->cost_unit_usd;
                        if ($usd === null) {
                            $errors[] = [
                                'type' => 'product_price',
                                'id' => (int) $price->id,
                                'reason' => 'missing_usd_source',
                            ];
                            continue;
                        }

                        $pair = 'USD_' . strtoupper((string) $price->currency);
                        $fx = $rates->get($pair)?->rate;

                        if ($fx === null) {
                            $errors[] = [
                                'type' => 'product_price',
                                'id' => (int) $price->id,
                                'reason' => 'missing_fx_rate',
                                'pair' => $pair,
                            ];
                            continue;
                        }

                        $minorUnit = (int) ($price->minor_unit ?? 2);
                        $minorUnit = $minorUnit > 0 ? $minorUnit : 2;

                        $scale = 10 ** $minorUnit;
                        $newMinor = (int) round(((float) $usd) * ((float) $fx) * $scale);

                        if ((int) $price->unit_price_minor !== $newMinor) {
                            $price->unit_price_minor = max(0, $newMinor);
                            $price->save();
                            $affected++;
                        }
                    }
                });
            }

            // PACKAGES (ProductPackage)
            if (in_array($data['scope'], ['packages', 'all'], true)) {
                $packages = ProductPackage::query()
                    ->with(['productPrice:id,currency,minor_unit,price_group_id,product_id']);

                if (isset($data['price_group_id']) || isset($data['currency']) || !empty($data['product_ids'])) {
                    $packages->whereHas('productPrice', function ($q) use ($data) {
                        if (isset($data['price_group_id'])) {
                            $q->where('price_group_id', (int) $data['price_group_id']);
                        }
                        if (isset($data['currency'])) {
                            $q->where('currency', $data['currency']);
                        }
                        if (!empty($data['product_ids'])) {
                            $q->whereIn('product_id', $data['product_ids']);
                        }
                    });
                }

                $packages->orderBy('id')->chunkById(500, function ($chunk) use ($rates, &$errors, &$affected) {
                    foreach ($chunk as $package) {
                        $usd = $package->suggested_usd ?? $package->cost_usd;
                        if ($usd === null) {
                            $errors[] = [
                                'type' => 'package',
                                'id' => (int) $package->id,
                                'reason' => 'missing_usd_source',
                            ];
                            continue;
                        }

                        $pp = $package->productPrice;
                        if (!$pp) {
                            $errors[] = [
                                'type' => 'package',
                                'id' => (int) $package->id,
                                'reason' => 'missing_product_price',
                            ];
                            continue;
                        }

                        $currency = strtoupper((string) ($pp->currency ?? ''));
                        if ($currency === '') {
                            $errors[] = [
                                'type' => 'package',
                                'id' => (int) $package->id,
                                'reason' => 'missing_currency',
                            ];
                            continue;
                        }

                        $pair = 'USD_' . $currency;
                        $fx = $rates->get($pair)?->rate;

                        if ($fx === null) {
                            $errors[] = [
                                'type' => 'package',
                                'id' => (int) $package->id,
                                'reason' => 'missing_fx_rate',
                                'pair' => $pair,
                            ];
                            continue;
                        }

                        $minorUnit = (int) ($pp->minor_unit ?? 2);
                        $minorUnit = $minorUnit > 0 ? $minorUnit : 2;

                        $scale = 10 ** $minorUnit;
                        $newMinor = (int) round(((float) $usd) * ((float) $fx) * $scale);

                        if ((int) $package->price_minor !== $newMinor) {
                            $package->price_minor = max(0, $newMinor);
                            $package->save();
                            $affected++;
                        }
                    }
                });
            }
        });

        return response()->json(
            $this->payload($this->snapshotFromFilters($data), $affected, $errors)
        );
    }

    public function batchUpdateTier(Request $request)
    {
        if ($request->filled('currency')) {
            $request->merge(['currency' => strtoupper((string) $request->input('currency'))]);
        }

        $data = $request->validate([
            'target' => ['required', Rule::in(['price', 'package'])],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1'],
            'action' => ['required', Rule::in(['set', 'increase_percent', 'decrease_percent', 'increase_amount_minor', 'decrease_amount_minor'])],
            'value' => [
                'required',
                'numeric',
                'min:0',
                Rule::when(
                    in_array((string) $request->input('action'), ['increase_amount_minor', 'decrease_amount_minor'], true),
                    ['integer']
                ),
            ],
            'currency' => ['nullable', 'string', Rule::in(['TRY', 'SYP'])],
            'price_group_id' => ['nullable', 'integer', 'min:1', 'exists:price_groups,id'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $affected = 0;
        $errors = [];

        DB::transaction(function () use ($data, $ids, &$affected, &$errors) {
            foreach ($ids as $id) {
                if ($data['target'] === 'price') {
                    $row = ProductPrice::query()->find($id);
                    if (!$row) {
                        $errors[] = ['id' => $id, 'reason' => 'not_found'];
                        continue;
                    }
                    if (isset($data['currency']) && strtoupper((string) $row->currency) !== $data['currency']) {
                        $errors[] = ['id' => $id, 'reason' => 'currency_mismatch'];
                        continue;
                    }
                    if (isset($data['price_group_id']) && (int) $row->price_group_id !== (int) $data['price_group_id']) {
                        $errors[] = ['id' => $id, 'reason' => 'price_group_mismatch'];
                        continue;
                    }

                    $old = (int) ($row->unit_price_minor ?? 0);
                    $new = $this->applyTierAction($old, (string) $data['action'], (float) $data['value']);
                    $row->unit_price_minor = $new;
                    $row->save();
                    $affected++;
                } else {
                    $row = ProductPackage::query()->with('productPrice')->find($id);
                    if (!$row) {
                        $errors[] = ['id' => $id, 'reason' => 'not_found'];
                        continue;
                    }

                    if (isset($data['currency']) && strtoupper((string) ($row->productPrice?->currency ?? '')) !== $data['currency']) {
                        $errors[] = ['id' => $id, 'reason' => 'currency_mismatch'];
                        continue;
                    }
                    if (isset($data['price_group_id']) && (int) ($row->productPrice?->price_group_id ?? 0) !== (int) $data['price_group_id']) {
                        $errors[] = ['id' => $id, 'reason' => 'price_group_mismatch'];
                        continue;
                    }

                    $old = (int) ($row->price_minor ?? 0);
                    $new = $this->applyTierAction($old, (string) $data['action'], (float) $data['value']);
                    $row->price_minor = $new;
                    $row->save();
                    $affected++;
                }
            }
        });

        return response()->json(
            $this->payload($this->snapshotByIds($data['target'], $ids), $affected, $errors)
        );
    }

    public function batchUpdateUsd(Request $request)
    {
        if ($request->filled('currency_scope')) {
            $request->merge(['currency_scope' => strtoupper((string) $request->input('currency_scope'))]);
        }

        $data = $request->validate([
            'target' => ['required', Rule::in(['product', 'package'])],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1'],
            'action' => ['required', Rule::in(['set', 'increase_percent', 'decrease_percent', 'increase_amount', 'decrease_amount', 'clear'])],
            'field' => ['required', Rule::in(['cost', 'suggested', 'both'])],
            'value' => ['nullable', 'numeric', 'min:0', 'required_unless:action,clear'],
            'currency_scope' => ['nullable', Rule::in(['TRY', 'USD', 'ALL'])],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $affected = 0;
        $errors = [];

        DB::transaction(function () use ($data, $ids, &$affected, &$errors) {
            foreach ($ids as $id) {
                if ($data['target'] === 'product') {
                    $row = Product::query()->find($id);
                    if (!$row) {
                        $errors[] = ['id' => $id, 'reason' => 'not_found'];
                        continue;
                    }

                    $scope = $data['currency_scope'] ?? 'ALL';
                    if ($scope !== 'ALL' && strtoupper((string) $row->currency_mode) !== $scope) {
                        $errors[] = ['id' => $id, 'reason' => 'currency_scope_mismatch'];
                        continue;
                    }

                    [$newCost, $newSuggested] = $this->applyUsdAction(
                        (float) ($row->cost_unit_usd ?? 0),
                        (float) ($row->suggested_unit_usd ?? 0),
                        (string) $data['field'],
                        (string) $data['action'],
                        isset($data['value']) ? (float) $data['value'] : null,
                        $data['action'] === 'clear',
                    );

                    if ($data['field'] === 'cost' || $data['field'] === 'both') {
                        $row->cost_unit_usd = $newCost;
                    }
                    if ($data['field'] === 'suggested' || $data['field'] === 'both') {
                        $row->suggested_unit_usd = $newSuggested;
                    }
                    $row->save();
                    $affected++;
                } else {
                    $row = ProductPackage::query()->find($id);
                    if (!$row) {
                        $errors[] = ['id' => $id, 'reason' => 'not_found'];
                        continue;
                    }

                    [$newCost, $newSuggested] = $this->applyUsdAction(
                        (float) ($row->cost_usd ?? 0),
                        (float) ($row->suggested_usd ?? 0),
                        (string) $data['field'],
                        (string) $data['action'],
                        isset($data['value']) ? (float) $data['value'] : null,
                        $data['action'] === 'clear',
                    );

                    if ($data['field'] === 'cost' || $data['field'] === 'both') {
                        $row->cost_usd = $newCost;
                    }
                    if ($data['field'] === 'suggested' || $data['field'] === 'both') {
                        $row->suggested_usd = $newSuggested;
                    }
                    $row->save();
                    $affected++;
                }
            }
        });

        return response()->json(
            $this->payload($this->snapshotByIds($data['target'], $ids), $affected, $errors)
        );
    }

    private function applyTierAction(int $old, string $action, float $value): int
    {
        return match ($action) {
            'set' => max(0, (int) round($value)),
            'increase_percent' => max(0, (int) round($old * (1 + ($value / 100)))),
            'decrease_percent' => max(0, (int) round($old * (1 - ($value / 100)))),
            'increase_amount_minor' => max(0, $old + (int) round($value)),
            'decrease_amount_minor' => max(0, $old - (int) round($value)),
            default => $old,
        };
    }

    private function applyUsdAction(float $cost, float $suggested, string $field, string $action, ?float $value, bool $clear): array
    {
        $mutate = function (float $old) use ($action, $value, $clear) {
            if ($clear) {
                return null;
            }

            $v = $value ?? 0.0;
            $new = match ($action) {
                'set' => $v,
                'increase_percent' => $old * (1 + ($v / 100)),
                'decrease_percent' => $old * (1 - ($v / 100)),
                'increase_amount' => $old + $v,
                'decrease_amount' => $old - $v,
                default => $old,
            };

            return max(0, round($new, 10));
        };

        return [
            ($field === 'cost' || $field === 'both') ? $mutate($cost) : $cost,
            ($field === 'suggested' || $field === 'both') ? $mutate($suggested) : $suggested,
        ];
    }

    private function payload(array $snapshot, int $affected, array $errors): array
    {
        return [
            'data' => [
                'snapshot' => $snapshot,
                'affected_count' => $affected,
                'errors' => $errors,
            ],
        ];
    }

    private function snapshotByIds(string $target, array $ids): array
    {
        if ($target === 'price') {
            return [
                'prices' => ProductPrice::query()
                    ->with('packages')
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->get(),
            ];
        }

        if ($target === 'package') {
            return [
                'packages' => ProductPackage::query()
                    ->with('productPrice')
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->get(),
            ];
        }

        if ($target === 'product') {
            return [
                'products' => Product::query()
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->get(),
            ];
        }

        return [
            'packages' => ProductPackage::query()
                ->with('productPrice')
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get(),
        ];
    }

    private function snapshotFromFilters(array $filters): array
    {
        $q = ProductPrice::query()->with(['product', 'packages', 'priceGroup']);

        if (isset($filters['price_group_id'])) {
            $q->where('price_group_id', (int) $filters['price_group_id']);
        }
        if (isset($filters['currency'])) {
            $q->where('currency', $filters['currency']);
        }
        if (!empty($filters['product_ids'])) {
            $q->whereIn('product_id', $filters['product_ids']);
        }

        return [
            'price_groups' => PriceGroup::query()->orderBy('id')->get(['id', 'code', 'name']),
            'rows' => $q->orderBy('product_id')->orderBy('currency')->orderBy('price_group_id')->get(),
        ];
    }
}