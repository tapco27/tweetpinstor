<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

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
            'requirement_key' => ['nullable','in:uid,player_id,email,phone'],
        ]);

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
            'requirement_key' => ['sometimes','nullable','in:uid,player_id,email,phone'],
        ]);

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