<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Support\ApiResponse;

class CatalogController extends Controller
{
    use ApiResponse;

    public function home()
    {
        $currency = app('user_currency');

        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $bannersQ = Banner::query()
            ->where('is_active', true)
            ->orderBy('sort_order');

        if ($currency) {
            $bannersQ->where(function ($q) use ($currency) {
                $q->whereNull('currency')->orWhere('currency', $currency);
            });
        }

        $banners = $bannersQ->get();

        $featuredQ = Product::query()
            ->where('is_active', true)
            ->where('is_featured', true)
            ->orderBy('sort_order')
            ->limit(20);

        // priceFor حسب العملة
        $featuredQ->with([
            'priceFor' => fn ($x) => $x->where('currency', $currency)->where('is_active', true),
            'priceFor.packages' => fn ($x) => $x->where('is_active', true)->orderBy('sort_order'),
            'priceFor.packages.productPrice',
        ])->whereHas('prices', fn ($x) => $x->where('currency', $currency)->where('is_active', true));

        $featured = $featuredQ->get();

        return $this->ok([
            'banners' => BannerResource::collection($banners)->resolve(request()),
            'categories' => CategoryResource::collection($categories)->resolve(request()),
            'featuredProducts' => ProductResource::collection($featured)->resolve(request()),
        ]);
    }

    public function categories()
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return $this->ok(CategoryResource::collection($categories));
    }

    public function banners()
    {
        $currency = app('user_currency');

        $q = Banner::query()
            ->where('is_active', true)
            ->orderBy('sort_order');

        if ($currency) {
            $q->where(function ($q) use ($currency) {
                $q->whereNull('currency')->orWhere('currency', $currency);
            });
        }

        return $this->ok(BannerResource::collection($q->get()));
    }

    public function products()
    {
        $currency = app('user_currency');

        $limit = (int) request('limit', 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;

        $category = request('category'); // category_id
        $term = trim((string) request('q', ''));

        $q = Product::query()
            ->where('is_active', true);

        if ($category !== null && $category !== '') {
            $q->where('category_id', $category);
        }

        if ($term !== '') {
            $needle = mb_strtolower($term);
            $like = '%'.str_replace(['%','_'], ['\%','\_'], $needle).'%';
            $q->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(name_ar) LIKE ?', [$like])
                   ->orWhereRaw('LOWER(COALESCE(name_tr, \'\')) LIKE ?', [$like])
                   ->orWhereRaw('LOWER(COALESCE(name_en, \'\')) LIKE ?', [$like]);
            });
        }

        $q->orderBy('sort_order')->orderByDesc('id');

        // user: سعر واحد حسب عملته + packages
        $q->with([
            'priceFor' => fn ($x) => $x->where('currency', $currency)->where('is_active', true),
            'priceFor.packages' => fn ($x) => $x->where('is_active', true)->orderBy('sort_order'),
            'priceFor.packages.productPrice',
        ])->whereHas('prices', fn ($x) => $x->where('currency', $currency)->where('is_active', true));

        $p = $q->paginate($limit);

        return $this->ok(
            ProductResource::collection($p->getCollection()),
            $this->paginationMeta($p)
        );
    }

    public function featured()
    {
        $currency = app('user_currency');

        $limit = (int) request('limit', 20);
        $limit = $limit > 0 ? min($limit, 50) : 20;

        $q = Product::query()
            ->where('is_active', true)
            ->where('is_featured', true)
            ->orderBy('sort_order')
            ->limit($limit);

        $q->with([
            'priceFor' => fn ($x) => $x->where('currency', $currency)->where('is_active', true),
            'priceFor.packages' => fn ($x) => $x->where('is_active', true)->orderBy('sort_order'),
            'priceFor.packages.productPrice',
        ])->whereHas('prices', fn ($x) => $x->where('currency', $currency)->where('is_active', true));

        return $this->ok(ProductResource::collection($q->get()));
    }

    public function product($id)
    {
        $currency = app('user_currency');

        $product = Product::query()
            ->where('id', $id)
            ->where('is_active', true)
            ->with([
                'priceFor' => fn ($x) => $x->where('currency', $currency)->where('is_active', true),
                'priceFor.packages' => fn ($x) => $x->where('is_active', true)->orderBy('sort_order'),
                'priceFor.packages.productPrice',
            ])
            ->firstOrFail();

        if (!$product->priceFor) {
            abort(404);
        }

        return $this->ok(new ProductResource($product));
    }
}
