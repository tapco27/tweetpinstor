<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductPrice;
use Illuminate\Http\Request;

class AdminProductPriceController extends Controller
{
    public function index(Request $r)
    {
        $q = ProductPrice::query()->with(['product','packages']);

        if ($r->filled('currency')) $q->where('currency', strtoupper($r->currency));
        if ($r->filled('product_id')) $q->where('product_id', (int)$r->product_id);

        return $q->orderByDesc('id')->paginate(50);
    }

    public function show($id)
    {
        return ProductPrice::with(['product','packages'])->findOrFail($id);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'product_id' => ['required','integer','exists:products,id'],
            'currency' => ['required','in:TRY,SYP'],
            'minor_unit' => ['required','integer','in:0,2'],
            'unit_price_minor' => ['nullable','integer','min:0'],
            'min_qty' => ['nullable','integer','min:1'],
            'max_qty' => ['nullable','integer','min:1'],
            'is_active' => ['nullable','boolean'],
        ]);

        return ProductPrice::create($data);
    }

    public function update(Request $r, $id)
    {
        $pp = ProductPrice::findOrFail($id);

        $data = $r->validate([
            'currency' => ['sometimes','in:TRY,SYP'],
            'minor_unit' => ['sometimes','integer','in:0,2'],
            'unit_price_minor' => ['sometimes','nullable','integer','min:0'],
            'min_qty' => ['sometimes','nullable','integer','min:1'],
            'max_qty' => ['sometimes','nullable','integer','min:1'],
            'is_active' => ['sometimes','boolean'],
        ]);

        $pp->update($data);
        return $pp->fresh(['product','packages']);
    }

    public function destroy($id)
    {
        $pp = ProductPrice::findOrFail($id);
        $pp->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
