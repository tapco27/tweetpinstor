<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\AdminOrderController;
use App\Http\Controllers\Api\V1\StripeWebhookController;

// User Wallet/Payment Methods
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\WalletController;

// Admin CRUD Controllers
use App\Http\Controllers\Api\V1\Admin\AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\AdminProductController;
use App\Http\Controllers\Api\V1\Admin\AdminProductPriceController;
use App\Http\Controllers\Api\V1\Admin\AdminProductPackageController;
use App\Http\Controllers\Api\V1\Admin\AdminBannerController;

// Admin Wallet/Payment Methods
use App\Http\Controllers\Api\V1\Admin\AdminPaymentMethodController;
use App\Http\Controllers\Api\V1\Admin\AdminWalletTopupController;

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
        // Orders admin actions
        Route::post('/orders/{id}/mark-paid', [AdminOrderController::class, 'markPaid']);
        Route::post('/orders/{id}/retry-delivery', [AdminOrderController::class, 'retryDelivery']);
        Route::get('/orders/delivery-failed', [AdminOrderController::class, 'deliveryFailed']);

        // Payment Methods (Admin)
        Route::get('/payment-methods', [AdminPaymentMethodController::class, 'index']);
        Route::post('/payment-methods', [AdminPaymentMethodController::class, 'store']);
        Route::get('/payment-methods/{id}', [AdminPaymentMethodController::class, 'show']);
        Route::put('/payment-methods/{id}', [AdminPaymentMethodController::class, 'update']);
        Route::delete('/payment-methods/{id}', [AdminPaymentMethodController::class, 'destroy']);

        // Wallet Topups (Admin)
        Route::get('/wallet-topups', [AdminWalletTopupController::class, 'index']);
        Route::get('/wallet-topups/{id}', [AdminWalletTopupController::class, 'show']);
        Route::get('/wallet-topups/{id}/receipt-url', [AdminWalletTopupController::class, 'receiptUrl']);
        Route::post('/wallet-topups/{id}/approve', [AdminWalletTopupController::class, 'approve']);
        Route::post('/wallet-topups/{id}/reject', [AdminWalletTopupController::class, 'reject']);

        // Admin CRUD
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

    // User protected (بدون user.currency)
    Route::middleware(['auth:api'])->group(function () {

        // متاح حتى لو currency = null
        Route::get('/me', [AuthController::class, 'me']);
        Route::middleware('throttle:auth')->post('/me/currency', [AuthController::class, 'setCurrency']);

        Route::middleware('throttle:auth')->post('/auth/logout', [AuthController::class, 'logout']);
        Route::middleware('throttle:auth')->post('/auth/refresh', [AuthController::class, 'refresh']);

        // كل ما تحتها يحتاج user.currency
        Route::middleware(['user.currency'])->group(function () {

            // Home (endpoint واحد ممتاز للفلاتر)
            Route::get('/home', [CatalogController::class, 'home']);

            // Catalog
            Route::get('/categories', [CatalogController::class, 'categories']);
            Route::get('/banners', [CatalogController::class, 'banners']);
            Route::get('/products', [CatalogController::class, 'products']);
            Route::get('/products/featured', [CatalogController::class, 'featured']);
            Route::get('/products/{id}', [CatalogController::class, 'product']);

            // Payment Methods (User)
            Route::get('/payment-methods', [PaymentMethodController::class, 'index']);

            // Wallet (User)
            Route::get('/wallet', [WalletController::class, 'show']);
            Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
            Route::get('/wallet/topups', [WalletController::class, 'topups']);
            Route::post('/wallet/topups', [WalletController::class, 'createTopup'])->middleware('throttle:wallet');

            // Orders
            Route::get('/orders', [OrderController::class, 'index']);
            Route::get('/orders/{id}', [OrderController::class, 'show']);
            Route::post('/orders/{id}/pay-wallet', [OrderController::class, 'payWithWallet'])
                ->middleware('throttle:wallet');
            Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:orders');
        });
    });
});
