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

  public function retrievePaymentIntent(string $id): array
  {
    $stripe = $this->client();
    $intent = $stripe->paymentIntents->retrieve($id, []);

    return [
      'id' => $intent->id,
      'client_secret' => $intent->client_secret,
      'status' => $intent->status,
    ];
  }

  public function verifyWebhook(string $payload, string $sigHeader): \Stripe\Event
  {
    $secret = config('services.stripe.webhook_secret');
    return \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
  }
}
