<?php

namespace App\Services\Fulfillment\Contracts;

use App\Models\Order;
use App\Services\Fulfillment\DTO\FulfillmentResult;

interface FulfillmentDriver
{
    public function place(Order $order): FulfillmentResult;

    public function check(Order $order, string $providerOrderId): FulfillmentResult;

    public function code(): string; // tweetpin, mock, ...
}
