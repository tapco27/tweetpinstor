<?php

namespace App\Services;

use Stripe\StripeClient;

class StripeService
{
  public function client(): StripeClient
  {
    return new StripeClient(config('services.stripe.secret'));
  }

  public function createPaymentIntent(string $currency, int $amountMinor, array $metadata = []): array
  {
    $stripe = $this->client();

    // Stripe expects amount in the smallest currency unit for supported currencies.
    // currency decision later: we keep logic; actual activation controlled by config.
    $intent = $stripe->paymentIntents->create([
      'amount' => $amountMinor,
      'currency' => strtolower($currency),
      'automatic_payment_methods' => ['enabled' => true],
      'metadata' => $metadata,
    ]);

    return [
      'id' => $intent->id,
      'client_secret' => $intent->client_secret,
    ];
  }

  public function verifyWebhook(string $payload, string $sigHeader): \Stripe\Event
  {
    $secret = config('services.stripe.webhook_secret');
    return \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
  }
}
