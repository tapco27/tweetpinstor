<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;

class AdminBannerController extends Controller
{
    public function index()
    {
        return Banner::query()->orderBy('sort_order')->paginate(50);
    }

    public function show($id)
    {
        return Banner::findOrFail($id);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'image_url' => ['required','string','max:2048'],
            'link_type' => ['nullable','in:product,category,external'],
            'link_value' => ['nullable','string','max:2048'],
            'currency' => ['nullable','in:TRY,SYP'],
            'is_active' => ['nullable','boolean'],
            'sort_order' => ['nullable','integer'],
        ]);

        return Banner::create($data);
    }

    public function update(Request $r, $id)
    {
        $b = Banner::findOrFail($id);

        $data = $r->validate([
            'image_url' => ['sometimes','string','max:2048'],
            'link_type' => ['sometimes','nullable','in:product,category,external'],
            'link_value' => ['sometimes','nullable','string','max:2048'],
            'currency' => ['sometimes','nullable','in:TRY,SYP'],
            'is_active' => ['sometimes','boolean'],
            'sort_order' => ['sometimes','integer'],
        ]);

        $b->update($data);
        return $b;
    }

    public function destroy($id)
    {
        $b = Banner::findOrFail($id);
        $b->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
