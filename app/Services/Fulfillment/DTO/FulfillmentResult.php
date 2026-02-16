<?php

namespace App\Services\Fulfillment\DTO;

final class FulfillmentResult
{
    public function __construct(
        public readonly string $state, // delivered|waiting|failed|noop
        public readonly ?string $providerOrderId = null,
        public readonly ?string $message = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly array $meta = [],
    ) {}

    public function shouldRetry(): bool
    {
        return $this->state === 'waiting' && $this->retryAfterSeconds !== null;
    }
}
