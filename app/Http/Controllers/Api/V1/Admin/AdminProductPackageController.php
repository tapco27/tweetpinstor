<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductPackage;
use Illuminate\Http\Request;

class AdminProductPackageController extends Controller
{
    public function index(Request $r)
    {
        $q = ProductPackage::query()->with(['productPrice.product']);

        if ($r->filled('product_price_id')) $q->where('product_price_id', (int)$r->product_price_id);

        return $q->orderBy('sort_order')->paginate(100);
    }

    public function show($id)
    {
        return ProductPackage::with(['productPrice.product'])->findOrFail($id);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'product_price_id' => ['required','integer','exists:product_prices,id'],
            'name_ar' => ['required','string','max:255'],
            'name_tr' => ['nullable','string','max:255'],
            'name_en' => ['nullable','string','max:255'],
            'value_label' => ['required','string','max:255'],
            'price_minor' => ['required','integer','min:0'],
            'is_popular' => ['nullable','boolean'],
            'is_active' => ['nullable','boolean'],
            'sort_order' => ['nullable','integer'],
        ]);

        return ProductPackage::create($data);
    }

    public function update(Request $r, $id)
    {
        $p = ProductPackage::findOrFail($id);

        $data = $r->validate([
            'name_ar' => ['sometimes','string','max:255'],
            'name_tr' => ['sometimes','nullable','string','max:255'],
            'name_en' => ['sometimes','nullable','string','max:255'],
            'value_label' => ['sometimes','string','max:255'],
            'price_minor' => ['sometimes','integer','min:0'],
            'is_popular' => ['sometimes','boolean'],
            'is_active' => ['sometimes','boolean'],
            'sort_order' => ['sometimes','integer'],
        ]);

        $p->update($data);
        return $p->fresh(['productPrice.product']);
    }

    public function destroy($id)
    {
        $p = ProductPackage::findOrFail($id);
        $p->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
