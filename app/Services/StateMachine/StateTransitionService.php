<?php

namespace App\Services\StateMachine;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

class StateTransitionService
{
    private const ORDER_TRANSITIONS = [
        'pending_payment' => ['paid', 'canceled'],
        'paid' => ['delivering', 'awaiting_manual_approval', 'refunded'],
        'awaiting_manual_approval' => ['delivering', 'failed'],
        'delivering' => ['delivered', 'failed'],
        'failed' => ['refunded'],
        'canceled' => [],
        'delivered' => [],
        'refunded' => [],
    ];

    private const DELIVERY_TRANSITIONS = [
        'not_started' => ['pending'],
        'pending' => ['processing', 'waiting_admin'],
        'processing' => ['waiting_provider', 'delivered', 'failed'],
        'waiting_provider' => ['processing', 'delivered', 'failed'],
        'waiting_admin' => ['delivered', 'failed'],
        'delivered' => [],
        'failed' => [],
        'canceled' => [],
    ];

    public function transitionOrderStatus(
        $order,
        OrderStatus $to,
        ?Authenticatable $actor = null,
        array $meta = []
    ): void {
        $from = $this->normalizeOrderStatus($order->status);

        $this->assertAllowed(self::ORDER_TRANSITIONS, $from->value, $to->value, "Order#{$order->id}");

        $old = ['status' => $order->status];
        $order->status = $to->value;
        $order->save();

        $this->audit($actor, $order, 'order.status.transition', $old, ['status' => $order->status], $meta);
    }

    public function transitionPaymentStatus(
        $order,
        PaymentStatus $to,
        ?Authenticatable $actor = null,
        array $meta = []
    ): void {
        $from = $this->normalizePaymentStatus($order->payment_status);

        // payment transitions (مختصر – قابل للتوسعة)
        $allowed = [
            'unpaid' => ['pending', 'requires_action', 'paid', 'canceled', 'failed'],
            'requires_action' => ['paid', 'failed', 'canceled'],
            'pending' => ['paid', 'failed', 'canceled'],
            'paid' => ['refunded'],
            'failed' => [],
            'canceled' => [],
            'refunded' => [],
        ];

        $this->assertAllowed($allowed, $from->value, $to->value, "Order#{$order->id}");

        $old = ['payment_status' => $order->payment_status];
        $order->payment_status = $to->value;
        $order->save();

        $this->audit($actor, $order, 'order.payment_status.transition', $old, ['payment_status' => $order->payment_status], $meta);
    }

    public function transitionDeliveryStatus(
        $delivery,
        DeliveryStatus $to,
        ?Authenticatable $actor = null,
        array $meta = []
    ): void {
        $from = $this->normalizeDeliveryStatus($delivery->status);

        $this->assertAllowed(self::DELIVERY_TRANSITIONS, $from->value, $to->value, "Delivery#{$delivery->id}");

        $old = ['status' => $delivery->status];
        $delivery->status = $to->value;
        $delivery->save();

        $this->audit($actor, $delivery, 'delivery.status.transition', $old, ['status' => $delivery->status], $meta);
    }

    private function assertAllowed(array $map, string $from, string $to, string $label): void
    {
        $allowed = $map[$from] ?? null;
        if ($allowed === null) {
            throw new InvalidStateTransitionException("Unknown state '{$from}' for {$label}");
        }
        if (!in_array($to, $allowed, true)) {
            throw new InvalidStateTransitionException("Invalid transition {$label}: {$from} -> {$to}");
        }
    }

    // Normalizers (تقبل قيم قديمة شائعة حتى لا نكسر بيانات Legacy)
    private function normalizeOrderStatus(?string $status): OrderStatus
    {
        return match ($status) {
            'pending', 'pending_payment', null => OrderStatus::PendingPayment,
            'paid' => OrderStatus::Paid,
            'awaiting_manual_approval', 'awaiting_approval', 'manual_pending' => OrderStatus::AwaitingManualApproval,
            'delivering', 'processing' => OrderStatus::Delivering,
            'delivered' => OrderStatus::Delivered,
            'failed' => OrderStatus::Failed,
            'canceled', 'cancelled' => OrderStatus::Canceled,
            'refunded' => OrderStatus::Refunded,
            default => throw new InvalidStateTransitionException("Unknown legacy order status '{$status}'"),
        };
    }

    private function normalizePaymentStatus(?string $status): PaymentStatus
    {
        return match ($status) {
            'unpaid', null => PaymentStatus::Unpaid,
            'pending' => PaymentStatus::Pending,
            'requires_action' => PaymentStatus::RequiresAction,
            'paid' => PaymentStatus::Paid,
            'failed' => PaymentStatus::Failed,
            'canceled', 'cancelled' => PaymentStatus::Canceled,
            'refunded' => PaymentStatus::Refunded,
            default => throw new InvalidStateTransitionException("Unknown legacy payment_status '{$status}'"),
        };
    }

    private function normalizeDeliveryStatus(?string $status): DeliveryStatus
    {
        return match ($status) {
            'not_started', null => DeliveryStatus::NotStarted,
            'pending' => DeliveryStatus::Pending,
            'processing' => DeliveryStatus::Processing,
            'waiting_provider' => DeliveryStatus::WaitingProvider,
            'waiting_admin' => DeliveryStatus::WaitingAdmin,
            'delivered' => DeliveryStatus::Delivered,
            'failed' => DeliveryStatus::Failed,
            'canceled', 'cancelled' => DeliveryStatus::Canceled,
            default => throw new InvalidStateTransitionException("Unknown legacy delivery status '{$status}'"),
        };
    }

    private function audit(
        ?Authenticatable $actor,
        $auditable,
        string $action,
        array $old,
        array $new,
        array $meta = []
    ): void {
        AuditLog::create([
            'actor_type' => $actor ? get_class($actor) : null,
            'actor_id' => $actor?->getAuthIdentifier(),
            'auditable_type' => get_class($auditable),
            'auditable_id' => $auditable->getKey(),
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'meta' => $meta,
            // ip/user_agent نملأها لاحقاً من middleware أو عند الاستدعاء
        ]);
    }
}
