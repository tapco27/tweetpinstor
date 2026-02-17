<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\ProductPrice;
use App\Services\PaymentChannelResolver;
use App\Services\PricingService;
use App\Services\StripeService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PricingService $pricing,
        private StripeService $stripe,
        private PaymentChannelResolver $resolver,
    ) {}

    public function index()
    {
        $limit = (int) request('limit', 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;

        $p = Order::query()
            ->where('user_id', auth('api')->id())
            ->with(['items.product', 'items.package', 'delivery'])
            ->orderByDesc('id')
            ->paginate($limit);

        return $this->ok(
            OrderResource::collection($p->getCollection()),
            $this->paginationMeta($p)
        );
    }

    public function show($id)
    {
        $order = Order::query()
            ->where('id', $id)
            ->where('user_id', auth('api')->id())
            ->with(['items.product', 'items.package', 'delivery'])
            ->firstOrFail();

        return $this->ok(new OrderResource($order));
    }

    public function payWithWallet($id, \App\Services\WalletService $wallets)
    {
        $userId = (int) auth('api')->id();

        $result = $wallets->payOrderWithWallet((int) $id, $userId);

        $order = \App\Models\Order::query()
            ->where('id', (int) $id)
            ->where('user_id', $userId)
            ->with(['items.product', 'items.package', 'delivery'])
            ->firstOrFail();

        // Dispatch delivery ONLY when payment was newly posted
        if (!empty($result['did_pay']) && (string) $order->status !== 'delivered') {
            \App\Jobs\DeliverOrderJob::dispatch((int) $order->id);
        }

        return $this->ok([
            'order' => (new \App\Http\Resources\OrderResource($order))->resolve(request()),
            'walletTransaction' => !empty($result['transaction'])
                ? (new \App\Http\Resources\WalletTransactionResource($result['transaction']))->resolve(request())
                : null,
            'did_pay' => (bool) ($result['did_pay'] ?? false),
        ]);
    }

    #[BodyParameter(
        name: 'metadata',
        description: 'Key-value object. Must include category requiredFields (e.g. uid/player_id/email/phone/wallet_id) when required.',
        required: false,
        type: 'object',
        infer: false,
        example: ['uid' => '123456']
    )]
    public function store(CreateOrderRequest $request)
    {
        $user = auth('api')->user();
        $currency = app('user_currency');
        $priceGroupId = app()->bound('price_group_id') ? (int) app('price_group_id') : 1;
        if ($priceGroupId <= 0) $priceGroupId = 1;

        if (!$currency) {
            return $this->fail('Currency not set', 422);
        }

        // Idempotency key
        $orderUuid = (string) $request->input('order_uuid');

        // Fast path: return existing
        $existing = Order::query()
            ->where('user_id', $user->id)
            ->where('order_uuid', $orderUuid)
            ->with(['items.product', 'items.package', 'delivery'])
            ->first();

        if ($existing) {
            return $this->respondExisting($existing);
        }

        $product = Product::query()
            ->where('id', $request->product_id)
            ->where('is_active', true)
            ->firstOrFail();

        $price = ProductPrice::query()
            ->where('product_id', $product->id)
            ->where('currency', $currency)
            ->where('price_group_id', $priceGroupId)
            ->where('is_active', true)
            ->firstOrFail();

        $package = null;
        if ($product->product_type === 'fixed_package') {
            $package = ProductPackage::query()
                ->where('id', $request->package_id)
                ->where('product_price_id', $price->id)
                ->where('is_active', true)
                ->firstOrFail();
        }

        $quantity = (int) ($request->quantity ?? 1);
        $meta = $request->metadata ?? [];
        $provider = $this->resolver->providerFor($currency); // manual | stripe

        try {
            return DB::transaction(function () use (
                $user,
                $currency,
                $product,
                $price,
                $package,
                $quantity,
                $meta,
                $provider,
                $orderUuid
            ) {
                // (اختياري) defensive: داخل الترانزكشن كمان افحص مرة ثانية
                $already = Order::query()
                    ->where('user_id', $user->id)
                    ->where('order_uuid', $orderUuid)
                    ->with(['items.product', 'items.package', 'delivery'])
                    ->first();

                if ($already) {
                    return $this->respondExisting($already);
                }

                $line = $this->pricing->calculateLine($product, $price, $package, $quantity);

                $order = new Order();
                $order->user_id = $user->id;
                $order->currency = $currency;
                $order->order_uuid = $orderUuid;

                $order->status = 'pending';

                $order->subtotal_amount_minor = (int) $line['total_price_minor'];
                $order->fees_amount_minor = 0;
                $order->total_amount_minor = (int) $line['total_price_minor'];

                $order->payment_provider = $provider;
                $order->payment_status = 'pending';

                // هنا قد يحدث unique violation تحت الضغط
                $order->save();

                $item = new OrderItem();
                $item->order_id = $order->id;
                $item->product_id = $product->id;
                $item->product_price_id = $price->id;
                $item->package_id = $package?->id;
                $item->quantity = (int) $line['quantity'];
                $item->unit_price_minor = (int) $line['unit_price_minor'];
                $item->total_price_minor = (int) $line['total_price_minor'];
                $item->metadata = $meta;
                $item->save();

                $order->load(['items.product', 'items.package', 'delivery']);
                $orderData = (new OrderResource($order))->resolve(request());

                if ($provider === 'stripe') {
                    $pi = $this->stripe->createPaymentIntent(
                        $currency,
                        (int) $order->total_amount_minor,
                        ['order_id' => (string) $order->id, 'user_id' => (string) $user->id]
                    );

                    $piId = (string) (is_array($pi) ? ($pi['id'] ?? '') : ($pi->id ?? ''));
                    $clientSecret = (string) (is_array($pi) ? ($pi['client_secret'] ?? '') : ($pi->client_secret ?? ''));

                    $order->stripe_payment_intent_id = $piId;
                    $order->payment_status = 'requires_action';
                    $order->save();

                    $order->load(['items.product', 'items.package', 'delivery']);
                    $orderData = (new OrderResource($order))->resolve(request());

                    return $this->ok([
                        'order' => $orderData,
                        'payment' => [
                            'provider' => 'stripe',
                            'status' => (string) $order->payment_status,
                            'stripePaymentIntentId' => (string) $order->stripe_payment_intent_id,
                            'stripeClientSecret' => $clientSecret,
                        ],
                    ], [], 201);
                }

                return $this->ok([
                    'order' => $orderData,
                    'payment' => [
                        'provider' => 'manual',
                        'status' => (string) $order->payment_status,
                    ],
                ], [], 201);
            });
        } catch (QueryException $e) {
            // Unique collision (idempotency under pressure):
            // إذا صار unique violation على (user_id, order_uuid) رجّع الطلب الموجود بدل 500.
            if ($this->isUniqueViolation($e)) {
                $existing = Order::query()
                    ->where('user_id', $user->id)
                    ->where('order_uuid', $orderUuid)
                    ->with(['items.product', 'items.package', 'delivery'])
                    ->first();

                if ($existing) {
                    return $this->respondExisting($existing);
                }
            }

            throw $e;
        }
    }

    private function respondExisting(Order $existing)
    {
        $orderData = (new OrderResource($existing))->resolve(request());

        if ((string) $existing->payment_provider === 'stripe') {
            $piId = (string) $existing->stripe_payment_intent_id;
            $clientSecret = '';

            if ($piId !== '') {
                $pi = $this->stripe->retrievePaymentIntent($piId);
                $clientSecret = (string) (is_array($pi) ? ($pi['client_secret'] ?? '') : ($pi->client_secret ?? ''));
            }

            return $this->ok([
                'order' => $orderData,
                'payment' => [
                    'provider' => 'stripe',
                    'status' => (string) $existing->payment_status,
                    'stripePaymentIntentId' => $piId,
                    'stripeClientSecret' => $clientSecret,
                ],
            ]);
        }

        return $this->ok([
            'order' => $orderData,
            'payment' => [
                'provider' => (string) $existing->payment_provider,
                'status' => (string) $existing->payment_status,
            ],
        ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // PostgreSQL: SQLSTATE 23505
        $sqlState = $e->errorInfo[0] ?? null;
        if ($sqlState === '23505') {
            return true;
        }

        // MySQL: 23000 / 1062
        $driverCode = $e->errorInfo[1] ?? null;
        if ($sqlState === '23000' && (int) $driverCode === 1062) {
            return true;
        }

        $msg = strtolower((string) $e->getMessage());
        return str_contains($msg, 'unique') || str_contains($msg, 'duplicate');
    }
}
