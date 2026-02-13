<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\AdminOrderController;
use App\Http\Controllers\Api\V1\StripeWebhookController;

// Admin CRUD Controllers
use App\Http\Controllers\Api\V1\Admin\AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\AdminProductController;
use App\Http\Controllers\Api\V1\Admin\AdminProductPriceController;
use App\Http\Controllers\Api\V1\Admin\AdminProductPackageController;
use App\Http\Controllers\Api\V1\Admin\AdminBannerController;

Route::prefix('v1')->group(function () {

    // Webhook بدون auth
    Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);

    // Auth (throttle)
    Route::middleware('throttle:auth')->post('/auth/register', [AuthController::class, 'register']);
    Route::middleware('throttle:auth')->post('/auth/login', [AuthController::class, 'login']);

    // Mock Fulfillment (اختبار فقط)
    if (app()->environment(['local', 'testing'])) {
        Route::post('/mock/fulfill', function (\Illuminate\Http\Request $r) {
            return response()->json([
                'ok' => true,
                'provider_code' => $r->input('provider_code'),
                'order_id' => $r->input('order_id'),
                'delivered' => true,
                'reference' => 'MOCK-' . $r->input('order_id'),
            ]);
        });
    }

    // Admin
    Route::middleware(['auth:api', 'admin', 'throttle:admin'])->prefix('admin')->group(function () {
        Route::post('/orders/{id}/mark-paid', [AdminOrderController::class, 'markPaid']);
        Route::post('/orders/{id}/retry-delivery', [AdminOrderController::class, 'retryDelivery']);
        Route::get('/orders/delivery-failed', [AdminOrderController::class, 'deliveryFailed']);

        Route::get('/categories', [AdminCategoryController::class, 'index']);
        Route::post('/categories', [AdminCategoryController::class, 'store']);
        Route::get('/categories/{id}', [AdminCategoryController::class, 'show']);
        Route::put('/categories/{id}', [AdminCategoryController::class, 'update']);
        Route::delete('/categories/{id}', [AdminCategoryController::class, 'destroy']);

        Route::get('/products', [AdminProductController::class, 'index']);
        Route::post('/products', [AdminProductController::class, 'store']);
        Route::get('/products/{id}', [AdminProductController::class, 'show']);
        Route::put('/products/{id}', [AdminProductController::class, 'update']);
        Route::delete('/products/{id}', [AdminProductController::class, 'destroy']);

        Route::get('/product-prices', [AdminProductPriceController::class, 'index']);
        Route::post('/product-prices', [AdminProductPriceController::class, 'store']);
        Route::get('/product-prices/{id}', [AdminProductPriceController::class, 'show']);
        Route::put('/product-prices/{id}', [AdminProductPriceController::class, 'update']);
        Route::delete('/product-prices/{id}', [AdminProductPriceController::class, 'destroy']);

        Route::get('/packages', [AdminProductPackageController::class, 'index']);
        Route::post('/packages', [AdminProductPackageController::class, 'store']);
        Route::get('/packages/{id}', [AdminProductPackageController::class, 'show']);
        Route::put('/packages/{id}', [AdminProductPackageController::class, 'update']);
        Route::delete('/packages/{id}', [AdminProductPackageController::class, 'destroy']);

        Route::get('/banners', [AdminBannerController::class, 'index']);
        Route::post('/banners', [AdminBannerController::class, 'store']);
        Route::get('/banners/{id}', [AdminBannerController::class, 'show']);
        Route::put('/banners/{id}', [AdminBannerController::class, 'update']);
        Route::delete('/banners/{id}', [AdminBannerController::class, 'destroy']);
    });

    // User protected
    Route::middleware(['auth:api', 'user.currency'])->group(function () {

        Route::get('/me', [AuthController::class, 'me']);

        Route::middleware('throttle:auth')->post('/auth/logout', [AuthController::class, 'logout']);
        Route::middleware('throttle:auth')->post('/auth/refresh', [AuthController::class, 'refresh']);

        // Home (endpoint واحد ممتاز للفلاتر)
        Route::get('/home', [CatalogController::class, 'home']);

        // Catalog
        Route::get('/categories', [CatalogController::class, 'categories']);
        Route::get('/banners', [CatalogController::class, 'banners']);
        Route::get('/products', [CatalogController::class, 'products']);
        Route::get('/products/featured', [CatalogController::class, 'featured']);
        Route::get('/products/{id}', [CatalogController::class, 'product']);

        // Orders
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{id}', [OrderController::class, 'show']);
        Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:orders');
    });
});
