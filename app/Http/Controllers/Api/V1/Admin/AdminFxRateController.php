<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\FxRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFxRateController extends Controller
{
    public function index()
    {
        $pairs = FxRate::query()
            ->whereIn('pair', ['USD_TRY', 'USD_SYP'])
            ->get()
            ->keyBy('pair');

        return response()->json([
            'data' => [
                'USD_TRY' => isset($pairs['USD_TRY']) ? (string) $pairs['USD_TRY']->rate : null,
                'USD_SYP' => isset($pairs['USD_SYP']) ? (string) $pairs['USD_SYP']->rate : null,
            ],
        ]);
    }

    public function update(Request $r)
    {
        $data = $r->validate([
            'USD_TRY' => ['nullable', 'numeric', 'gt:0'],
            'USD_SYP' => ['nullable', 'numeric', 'gt:0'],
        ]);

        DB::transaction(function () use ($data) {
            foreach (['USD_TRY' => 'TRY', 'USD_SYP' => 'SYP'] as $pair => $quote) {
                if (!array_key_exists($pair, $data) || $data[$pair] === null) {
                    continue;
                }

                FxRate::query()->updateOrCreate(
                    ['pair' => $pair],
                    [
                        'base_currency' => 'USD',
                        'quote_currency' => $quote,
                        'rate' => $data[$pair],
                        'updated_by' => (int) auth('api')->id(),
                    ]
                );
            }
        });

        return $this->index();
    }
}
