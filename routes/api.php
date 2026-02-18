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
use App\Http\Controllers\Api\V1\Admin\AdminDigitalPinController;
use App\Http\Controllers\Api\V1\Admin\AdminProviderIntegrationController;
use App\Http\Controllers\Api\V1\Admin\AdminPriceGroupController;
use App\Http\Controllers\Api\V1\Admin\AdminTweetPinController;
use App\Http\Controllers\Api\V1\Admin\AdminTweetPinMappingController;
use App\Http\Controllers\Api\V1\Admin\AdminFxRateController;
use App\Http\Controllers\Api\V1\Admin\AdminUsdPricingController;
use App\Http\Controllers\Api\V1\Admin\AdminPricingController;

// Admin Wallet/Payment Methods
use App\Http\Controllers\Api\V1\Admin\AdminPaymentMethodController;
use App\Http\Controllers\Api\V1\Admin\AdminWalletTopupController;

Route::prefix('v1')->group(function () {

    // Webhook بدون auth
    Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);

    // Auth (throttle)
    Route::middleware('throttle:auth')->post('/auth/register', [AuthController::class, 'register']);
    Route::middleware('throttle:auth')->post('/auth/login', [AuthController::class, 'login']);
    Route::middleware('throttle:auth')->post('/auth/google', [AuthController::class, 'loginWithGoogle']);
    Route::middleware('throttle:auth')->post('/auth/apple', [AuthController::class, 'loginWithApple']);

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
        Route::post('/orders/{id}/refund-wallet', [AdminOrderController::class, 'refundWallet']);

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
        Route::get('/products/{id}', [AdminProductController::class, 'show'])->whereNumber('id');
        Route::put('/products/{id}', [AdminProductController::class, 'update'])->whereNumber('id');
        Route::delete('/products/{id}', [AdminProductController::class, 'destroy'])->whereNumber('id');

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

        // Digital Pins Inventory
        Route::get('/digital-pins', [AdminDigitalPinController::class, 'index']);
        Route::get('/digital-pins/stock', [AdminDigitalPinController::class, 'stock']);
        Route::post('/digital-pins/bulk', [AdminDigitalPinController::class, 'bulkStore']);

        // Provider Integrations (API + Inventory)
        Route::get('/provider-templates', [AdminProviderIntegrationController::class, 'templates']);
        Route::get('/provider-integrations', [AdminProviderIntegrationController::class, 'index']);
        Route::post('/provider-integrations', [AdminProviderIntegrationController::class, 'store']);
        Route::get('/provider-integrations/{id}', [AdminProviderIntegrationController::class, 'show']);
        Route::put('/provider-integrations/{id}', [AdminProviderIntegrationController::class, 'update']);
        Route::delete('/provider-integrations/{id}', [AdminProviderIntegrationController::class, 'destroy']);

        // Tweet-Pin Provider helpers
        Route::get('/provider-integrations/{id}/tweetpin/profile', [AdminTweetPinController::class, 'profile']);
        Route::get('/provider-integrations/{id}/tweetpin/products', [AdminTweetPinController::class, 'products']);
        Route::get('/provider-integrations/{id}/tweetpin/content/{parentId}', [AdminTweetPinController::class, 'content']);
        Route::get('/provider-integrations/{id}/tweetpin/check', [AdminTweetPinController::class, 'check']);

        // Phase 7: Tweet-Pin mapping helper
        Route::put('/products/{id}/tweetpin/mapping', [AdminTweetPinMappingController::class, 'update'])->whereNumber('id');

        // Pricing Tiers (Price Groups)
        Route::get('/price-groups', [AdminPriceGroupController::class, 'index']);
        Route::post('/price-groups', [AdminPriceGroupController::class, 'store']);
        Route::get('/price-groups/{id}', [AdminPriceGroupController::class, 'show'])->whereNumber('id');
        Route::put('/price-groups/{id}', [AdminPriceGroupController::class, 'update'])->whereNumber('id');
        Route::delete('/price-groups/{id}', [AdminPriceGroupController::class, 'destroy'])->whereNumber('id');

        // FX Rates + USD Pricing
        Route::get('/fx-rates', [AdminFxRateController::class, 'index']);
        Route::put('/fx-rates', [AdminFxRateController::class, 'update']);
        Route::put('/products/{id}/usd-pricing', [AdminUsdPricingController::class, 'updateProduct'])->whereNumber('id');
        Route::put('/packages/{id}/usd-pricing', [AdminUsdPricingController::class, 'updatePackage'])->whereNumber('id');

        // Pricing Matrix (Price Groups / Pricing Tiers)
        Route::get('/pricing/grid', [AdminPricingController::class, 'grid']);
        Route::post('/pricing/recalculate-usd', [AdminPricingController::class, 'recalculateUsd']);
        Route::post('/pricing/batch-update-tier', [AdminPricingController::class, 'batchUpdateTier']);
        Route::post('/pricing/batch-update-usd', [AdminPricingController::class, 'batchUpdateUsd']);

        // Product Catalog helpers
        Route::put('/products/order', [AdminProductController::class, 'updateOrder']);
        Route::put('/products/bulk-provider', [AdminProductController::class, 'bulkProvider']);
    });

    // Public Catalog (عملة افتراضية TRY + يدعم قراءة عملة المستخدم إذا أرسل Bearer token)
    Route::middleware(['currency.resolve', 'price.group'])->group(function () {

        // Home (endpoint واحد ممتاز للفلاتر)
        Route::get('/home', [CatalogController::class, 'home']);

        // Catalog
        Route::get('/categories', [CatalogController::class, 'categories']);
        Route::get('/banners', [CatalogController::class, 'banners']);
        Route::get('/products', [CatalogController::class, 'products']);
        Route::get('/products/featured', [CatalogController::class, 'featured']);
        Route::get('/products/{id}', [CatalogController::class, 'product'])->whereNumber('id');
    });

    // User protected (بدون user.currency)
    Route::middleware(['auth:api'])->group(function () {

        // متاح حتى لو currency = null
        Route::get('/me', [AuthController::class, 'me']);
        Route::middleware('throttle:auth')->post('/me/currency', [AuthController::class, 'setCurrency']);

        Route::middleware('throttle:auth')->post('/auth/logout', [AuthController::class, 'logout']);
        Route::middleware('throttle:auth')->post('/auth/refresh', [AuthController::class, 'refresh']);

        // كل ما تحتها يحتاج user.currency (للشراء/المدفوعات/المحفظة)
        Route::middleware(['user.currency', 'price.group'])->group(function () {

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
