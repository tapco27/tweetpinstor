<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\PurchaseRequirements;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminCategoryController extends Controller
{
    public function index()
    {
        return Category::query()->orderBy('sort_order')->paginate(50);
    }

    public function show($id)
    {
        return Category::findOrFail($id);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'name_ar' => ['required','string','max:255'],
            'name_tr' => ['nullable','string','max:255'],
            'name_en' => ['nullable','string','max:255'],
            'sort_order' => ['nullable','integer'],
            'is_active' => ['nullable','boolean'],

            // New
            'purchase_mode' => ['nullable', Rule::in(['fixed_package', 'flexible_quantity'])],
            'requirements' => ['nullable','array','max:10'],
            'requirements.*' => ['string', Rule::in(PurchaseRequirements::ALLOWED_KEYS)],

            // Legacy (deprecated)
            'requirement_key' => ['nullable', Rule::in(PurchaseRequirements::ALLOWED_KEYS)],
        ]);

        // Normalize requirements
        $requirements = PurchaseRequirements::normalize($data['requirements'] ?? null);
        if (count($requirements) === 0 && !empty($data['requirement_key'])) {
            $requirements = [trim((string) $data['requirement_key'])];
        }
        $data['requirements'] = $requirements;

        // Keep legacy column in sync (first item)
        $data['requirement_key'] = $requirements[0] ?? ($data['requirement_key'] ?? null);

        return Category::create($data);
    }

    public function update(Request $r, $id)
    {
        $cat = Category::findOrFail($id);

        $data = $r->validate([
            'name_ar' => ['sometimes','string','max:255'],
            'name_tr' => ['sometimes','nullable','string','max:255'],
            'name_en' => ['sometimes','nullable','string','max:255'],
            'sort_order' => ['sometimes','integer'],
            'is_active' => ['sometimes','boolean'],

            // New
            'purchase_mode' => ['sometimes','nullable', Rule::in(['fixed_package', 'flexible_quantity'])],
            'requirements' => ['sometimes','nullable','array','max:10'],
            'requirements.*' => ['string', Rule::in(PurchaseRequirements::ALLOWED_KEYS)],

            // Legacy (deprecated)
            'requirement_key' => ['sometimes','nullable', Rule::in(PurchaseRequirements::ALLOWED_KEYS)],
        ]);

        // Normalize requirements on update when provided (or when legacy provided)
        $hasRequirements = array_key_exists('requirements', $data);
        $hasLegacy = array_key_exists('requirement_key', $data);

        if ($hasRequirements || $hasLegacy) {
            $requirements = PurchaseRequirements::normalize($data['requirements'] ?? null);
            if (count($requirements) === 0 && !empty($data['requirement_key'])) {
                $requirements = [trim((string) $data['requirement_key'])];
            }

            $data['requirements'] = $requirements;
            $data['requirement_key'] = $requirements[0] ?? ($data['requirement_key'] ?? null);
        }

        $cat->update($data);
        return $cat;
    }

    public function destroy($id)
    {
        $cat = Category::findOrFail($id);
        $cat->delete();
        return response()->json(['message' => 'Deleted']);
    }
}