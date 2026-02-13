<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class AdminProductController extends Controller
{
    public function index()
    {
        return Product::query()
            ->with(['category','prices.packages'])
            ->orderBy('sort_order')
            ->paginate(50);
    }

    public function show($id)
    {
        return Product::with(['category','prices.packages'])->findOrFail($id);
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

            // fulfillment fields
            'fulfillment_type' => ['nullable','string','max:50'],
            'provider_code' => ['nullable','string','max:100'],
            'fulfillment_config' => ['nullable','array'],
        ]);

        if (isset($data['fulfillment_config'])) {
            $data['fulfillment_config'] = $data['fulfillment_config']; // cast jsonb via model if set
        }

        return Product::create($data);
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

            'fulfillment_type' => ['sometimes','nullable','string','max:50'],
            'provider_code' => ['sometimes','nullable','string','max:100'],
            'fulfillment_config' => ['sometimes','nullable','array'],
        ]);

        $p->update($data);
        return $p->fresh(['category','prices.packages']);
    }

    public function destroy($id)
    {
        $p = Product::findOrFail($id);
        $p->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
