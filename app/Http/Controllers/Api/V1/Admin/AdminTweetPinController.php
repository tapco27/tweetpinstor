<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProviderIntegration;
use App\Services\Providers\TweetPinApiClient;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin helpers to test Tweet-Pin ProviderIntegration.
 *
 * Routes:
 * - GET /admin/provider-integrations/{id}/tweetpin/profile
 * - GET /admin/provider-integrations/{id}/tweetpin/products
 * - GET /admin/provider-integrations/{id}/tweetpin/content/{parentId}
 * - GET /admin/provider-integrations/{id}/tweetpin/check
 */
class AdminTweetPinController extends Controller
{
    public function __construct(private TweetPinApiClient $tweetPin) {}

    public function profile($id)
    {
        $integration = $this->loadTweetPinIntegration($id);
        return response()->json($this->tweetPin->profile($integration));
    }

    public function products(Request $r, $id)
    {
        $integration = $this->loadTweetPinIntegration($id);

        // products_id can be comma-separated or array
        $ids = $r->query('products_id');
        $base = (bool) ((int) $r->query('base', 0) === 1);

        $productIds = null;
        if (is_string($ids) && trim($ids) !== '') {
            $productIds = array_values(array_filter(array_map('trim', explode(',', $ids))));
        } elseif (is_array($ids)) {
            $productIds = $ids;
        }

        return response()->json($this->tweetPin->products($integration, $productIds, $base));
    }

    public function content($id, $parentId)
    {
        $integration = $this->loadTweetPinIntegration($id);
        return response()->json($this->tweetPin->content($integration, (int) $parentId));
    }

    public function check(Request $r, $id)
    {
        $integration = $this->loadTweetPinIntegration($id);

        $orders = $r->query('orders');
        $uuid = (bool) ((int) $r->query('uuid', 0) === 1);

        if (is_string($orders) && trim($orders) !== '') {
            // Accept both "ID1,ID2" and "[ID1,ID2]" forms
            $clean = trim($orders);
            $clean = trim($clean, "[] ");
            $ordersList = array_values(array_filter(array_map('trim', explode(',', $clean))));
        } elseif (is_array($orders)) {
            $ordersList = $orders;
        } else {
            throw ValidationException::withMessages([
                'orders' => ['orders query param is required'],
            ]);
        }

        return response()->json($this->tweetPin->check($integration, $ordersList, $uuid));
    }

    private function loadTweetPinIntegration($id): ProviderIntegration
    {
        /** @var ProviderIntegration $integration */
        $integration = ProviderIntegration::query()->findOrFail((int) $id);

        if ((string) $integration->template_code !== 'tweet_pin' && (string) $integration->template_code !== 'tweetpin') {
            throw ValidationException::withMessages([
                'provider_integration_id' => ['Integration is not Tweet-Pin template'],
            ]);
        }

        return $integration;
    }
}
