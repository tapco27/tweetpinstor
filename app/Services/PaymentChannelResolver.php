<?php

namespace App\Services;

class PaymentChannelResolver
{
    public function providerFor(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        // إذا Stripe مو مفعّل -> Manual
        if (!(bool) config('services.stripe.enabled')) {
            return 'manual';
        }

        // حالياً Stripe فقط لـ TRY (SYP غير مدعومة)
        if ($currency !== 'TRY') {
            return 'manual';
        }

        // إذا ما في Secret صحيح -> Manual
        $secret = (string) config('services.stripe.secret');
        if ($secret === '' || str_contains($secret, '_xxx')) {
            return 'manual';
        }

        return 'stripe';
    }
}
