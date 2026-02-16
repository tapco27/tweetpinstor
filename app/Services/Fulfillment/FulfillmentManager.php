<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Services\Fulfillment\Contracts\FulfillmentDriver;
use RuntimeException;

final class FulfillmentManager
{
    /** @var array<string,FulfillmentDriver> */
    private array $drivers = [];

    /**
     * @param iterable<FulfillmentDriver> $drivers
     */
    public function __construct(iterable $drivers)
    {
        foreach ($drivers as $d) {
            $this->drivers[$d->code()] = $d;
        }
    }

    public function driverForOrder(Order $order): FulfillmentDriver
    {
        $product = $order->items->first()?->product;
        $code = (string) ($product?->provider_code ?? '');

        if ($code === '' || !isset($this->drivers[$code])) {
            throw new RuntimeException("Fulfillment driver not found for provider_code={$code}");
        }

        return $this->drivers[$code];
    }
}
