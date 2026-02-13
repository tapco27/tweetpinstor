<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Support\ApiResponse;

class PaymentMethodController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $currency = (string) app('user_currency');
        $scope = (string) request('scope', 'both');

        if (!in_array($scope, ['topup', 'order', 'both'], true)) {
            return $this->fail('Invalid scope', 422);
        }

        $q = PaymentMethod::query()
            ->where('is_active', true)
            ->where(function ($x) use ($currency) {
                $x->whereNull('currency')->orWhere('currency', $currency);
            });

        if ($scope !== 'both') {
            $q->whereIn('scope', [$scope, 'both']);
        }

        $methods = $q->orderBy('id', 'desc')->get();

        return $this->ok(PaymentMethodResource::collection($methods));
    }
}
