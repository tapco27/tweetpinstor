<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProviderIntegration;
use App\Services\Providers\BsvApiClient;
use App\Services\Providers\FreeKasaApiClient;
use App\Services\Providers\TweetPinApiClient;
use Illuminate\Validation\ValidationException;

class AdminProviderProbeController extends Controller
{
    public function __construct(
        private TweetPinApiClient $tweetPin,
        private FreeKasaApiClient $freeKasa,
        private BsvApiClient $bsv,
    ) {}

    public function profile($id, string $provider)
    {
        $integration = $this->loadIntegrationByProvider($id, $provider);

        return response()->json(match (strtolower($provider)) {
            'tweetpin', 'tweet_pin', 'tweet-pin' => $this->tweetPin->profile($integration),
            'free_kasa', 'freekasa' => $this->freeKasa->profile($integration),
            'bsv' => $this->bsv->profile($integration),
            default => ['ok' => false, 'error' => 'Unsupported provider probe'],
        });
    }

    public function products($id, string $provider)
    {
        $integration = $this->loadIntegrationByProvider($id, $provider);

        return response()->json(match (strtolower($provider)) {
            'tweetpin', 'tweet_pin', 'tweet-pin' => $this->tweetPin->products($integration),
            'free_kasa', 'freekasa' => $this->freeKasa->products($integration),
            'bsv' => $this->bsv->products($integration),
            default => ['ok' => false, 'error' => 'Unsupported provider probe'],
        });
    }

    private function loadIntegrationByProvider($id, string $provider): ProviderIntegration
    {
        $integration = ProviderIntegration::query()->findOrFail((int) $id);

        $provider = strtolower(trim($provider));
        $expectedCodes = match ($provider) {
            'tweetpin', 'tweet_pin', 'tweet-pin' => ['tweet_pin', 'tweetpin', 'tweet-pin'],
            'free_kasa', 'freekasa' => ['free_kasa'],
            'bsv' => ['bsv'],
            default => throw ValidationException::withMessages([
                'provider' => ['Unsupported provider. Allowed: tweet_pin, free_kasa, bsv'],
            ]),
        };

        if (!in_array((string) $integration->template_code, $expectedCodes, true)) {
            throw ValidationException::withMessages([
                'provider_integration_id' => ['Integration template_code does not match provider route parameter'],
            ]);
        }

        return $integration;
    }
}
