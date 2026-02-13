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
use Dedoc\Scramble\Attributes\BodyParameter; // ✅ add
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

    #[BodyParameter(
        name: 'metadata',
        description: 'Key-value object. Must include category requirement_key (uid/player_id/email/phone) when required.',
        required: false,
        type: 'object',
        infer: false,
        example: ['uid' => '123456']
    )]
    public function store(CreateOrderRequest $request)
    {
        $user = auth('api')->user();
        $currency = app('user_currency');

        if (!$currency) {
            return $this->fail('Currency not set', 422);
        }

        $product = Product::query()
            ->where('id', $request->product_id)
            ->where('is_active', true)
            ->firstOrFail();

        $price = ProductPrice::query()
            ->where('product_id', $product->id)
            ->where('currency', $currency)
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

        return DB::transaction(function () use ($user, $currency, $product, $price, $package, $quantity, $meta, $provider) {

            $line = $this->pricing->calculateLine($product, $price, $package, $quantity);

            $order = new Order();
            $order->user_id = $user->id;
            $order->currency = $currency;

            $order->status = 'pending';

            $order->subtotal_amount_minor = (int) $line['total_price_minor'];
            $order->fees_amount_minor = 0;
            $order->total_amount_minor = (int) $line['total_price_minor'];

            $order->payment_provider = $provider;
            $order->payment_status = 'pending';
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

            // تجهيز Resource
            $order->load(['items.product', 'items.package', 'delivery']);
            $orderData = (new OrderResource($order))->resolve(request());

            if ($provider === 'stripe') {
                $pi = $this->stripe->createPaymentIntent(
                    $currency,
                    (int) $order->total_amount_minor,
                    ['order_id' => (string) $order->id, 'user_id' => (string) $user->id]
                );

                $order->stripe_payment_intent_id = $pi['id'];
                $order->payment_status = 'requires_action';
                $order->save();

                // تحديث orderData بعد حفظ PI + status
                $order->load(['items.product', 'items.package', 'delivery']);
                $orderData = (new OrderResource($order))->resolve(request());

                return $this->ok([
                    'order' => $orderData,
                    'payment' => [
                        'provider' => 'stripe',
                        'status' => (string) $order->payment_status,
                        'stripePaymentIntentId' => (string) $order->stripe_payment_intent_id,
                        'stripeClientSecret' => (string) $pi['client_secret'],
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
    }
}
