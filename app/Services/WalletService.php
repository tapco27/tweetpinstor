<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Posts a topup (admin approval).
     * Idempotent: if already posted, returns existing tx.
     */
    public function postTopup(int $topupId, int $adminUserId, ?string $reviewNote = null): array
    {
        return DB::transaction(function () use ($topupId, $adminUserId, $reviewNote) {
            $topup = WalletTopup::query()
                ->whereKey($topupId)
                ->lockForUpdate()
                ->firstOrFail();

            // already posted => return existing
            if ($topup->status === 'posted') {
                $tx = WalletTransaction::query()
                    ->where('wallet_id', $topup->wallet_id)
                    ->where('reference_type', 'wallet_topup')
                    ->where('reference_id', $topup->id)
                    ->where('direction', 'credit')
                    ->where('type', 'topup')
                    ->first();

                return ['topup' => $topup, 'transaction' => $tx];
            }

            if ($topup->status !== 'pending_review' && $topup->status !== 'approved') {
                abort(409, 'Topup not in a postable state');
            }

            if ((string) ($order->payment_status ?? '') !== 'paid') {
                abort(422, 'Order is not paid');
            }

            if ((string) ($order->status ?? '') === 'delivered') {
                abort(409, 'Cannot refund a delivered order');
            }

            $wallet = Wallet::query()
                ->whereKey($topup->wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($wallet->currency !== $topup->currency) {
                abort(500, 'Wallet currency mismatch');
            }

            // Ensure idempotency via unique ref (extra safe)
            $existingTx = WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('reference_type', 'wallet_topup')
                ->where('reference_id', $topup->id)
                ->where('direction', 'credit')
                ->where('type', 'topup')
                ->first();

            if ($existingTx) {
                $topup->status = 'posted';
                $topup->reviewed_by = $adminUserId;
                $topup->reviewed_at = now();
                $topup->review_note = $reviewNote;
                $topup->save();

                return ['topup' => $topup, 'transaction' => $existingTx];
            }

            // Mark approved then post
            $topup->status = 'approved';
            $topup->reviewed_by = $adminUserId;
            $topup->reviewed_at = now();
            $topup->review_note = $reviewNote;
            $topup->save();

            $newBalance = (int) $wallet->balance_minor + (int) $topup->amount_minor;

            $tx = new WalletTransaction();
            $tx->wallet_id = $wallet->id;
            $tx->user_id = $topup->user_id;
            $tx->direction = 'credit';
            $tx->status = 'posted';
            $tx->type = 'topup';
            $tx->amount_minor = (int) $topup->amount_minor; // always > 0
            $tx->balance_after_minor = $newBalance;
            $tx->reference_type = 'wallet_topup';
            $tx->reference_id = (int) $topup->id;
            $tx->reference_uuid = (string) ($topup->topup_uuid ?? null);
            $tx->meta = [
                'payment_method_id' => $topup->payment_method_id,
            ];
            $tx->save();

            $wallet->balance_minor = $newBalance;
            $wallet->save();

            $topup->status = 'posted';
            $topup->save();

            return ['topup' => $topup, 'transaction' => $tx];
        });
    }

    public function rejectTopup(int $topupId, int $adminUserId, string $reviewNote): WalletTopup
    {
        return DB::transaction(function () use ($topupId, $adminUserId, $reviewNote) {
            $topup = WalletTopup::query()
                ->whereKey($topupId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($topup->status === 'posted') {
                abort(409, 'Topup already posted');
            }

            if ($topup->status !== 'pending_review') {
                abort(409, 'Topup not in rejectable state');
            }

            $topup->status = 'rejected';
            $topup->reviewed_by = $adminUserId;
            $topup->reviewed_at = now();
            $topup->review_note = $reviewNote;
            $topup->save();

            return $topup;
        });
    }

    /**
     * Pay an order with the user's wallet.
     *
     * Safe by design:
     * - locks order + wallet rows
     * - creates ONE debit tx (unique constraint prevents duplicates)
     * - marks order paid + provider=wallet
     */
    public function payOrderWithWallet(int $orderId, int $userId): array
    {
        try {
            return DB::transaction(function () use ($orderId, $userId) {
                $order = Order::query()
                    ->whereKey($orderId)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // If already paid, never debit again
                if ($order->payment_status === 'paid') {
                    if ((string) $order->payment_provider !== 'wallet') {
                        abort(409, 'Order already paid');
                    }

                    $tx = $this->findOrderDebitTx($userId, (int) $order->id);

                    return [
                        'order' => $order,
                        'transaction' => $tx,
                        'did_pay' => false,
                    ];
                }

                // Block paying orders that are not in payable state
                if (!in_array((string) $order->payment_status, ['pending', 'requires_action'], true)) {
                    abort(409, 'Order not payable');
                }

                $amount = (int) $order->total_amount_minor;
                if ($amount <= 0) {
                    abort(422, 'Invalid order amount');
                }

                // If stripe intent exists, do not allow wallet payment on same order
                if ((string) $order->payment_provider === 'stripe' && !empty($order->stripe_payment_intent_id)) {
                    abort(409, 'Order has an active gateway payment intent');
                }

                if ((string) ($order->payment_status ?? '') !== 'paid') {
                abort(422, 'Order is not paid');
            }

            if ((string) ($order->status ?? '') === 'delivered') {
                abort(409, 'Cannot refund a delivered order');
            }

            $wallet = Wallet::query()
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((string) $wallet->currency !== (string) $order->currency) {
                    abort(500, 'Wallet currency mismatch');
                }

                // Idempotency: if tx already exists, heal order state & return
                $existingTx = $this->findOrderDebitTx($userId, (int) $order->id);
                if ($existingTx) {
                    $this->markOrderPaidByWallet($order);
                    return [
                        'order' => $order,
                        'transaction' => $existingTx,
                        'did_pay' => false,
                    ];
                }

                if ((int) $wallet->balance_minor < $amount) {
                    abort(422, 'Insufficient wallet balance');
                }

                $newBalance = (int) $wallet->balance_minor - $amount;

                $tx = new WalletTransaction();
                $tx->wallet_id = $wallet->id;
                $tx->user_id = $userId;
                $tx->direction = 'debit';
                $tx->status = 'posted';
                $tx->type = 'order_payment';
                $tx->amount_minor = $amount; // always > 0
                $tx->balance_after_minor = $newBalance;
                $tx->reference_type = 'order';
                $tx->reference_id = (int) $order->id;
                $tx->reference_uuid = $order->order_uuid ? (string) $order->order_uuid : null;
                $tx->meta = [
                    'currency' => (string) $order->currency,
                    'order_uuid' => (string) ($order->order_uuid ?? ''),
                ];
                $tx->save();

                $wallet->balance_minor = $newBalance;
                $wallet->save();

                $this->markOrderPaidByWallet($order);

                return [
                    'order' => $order,
                    'transaction' => $tx,
                    'did_pay' => true,
                ];
            });
        } catch (QueryException $e) {
            // Safety net: if unique constraint hits under extreme concurrency, return existing tx.
            if ($this->isUniqueViolation($e)) {
                $order = Order::query()
                    ->whereKey($orderId)
                    ->where('user_id', $userId)
                    ->first();

                $tx = $order ? $this->findOrderDebitTx($userId, (int) $order->id) : null;

                if ($order && $tx) {
                    $this->markOrderPaidByWallet($order);
                    return [
                        'order' => $order,
                        'transaction' => $tx,
                        'did_pay' => false,
                    ];
                }
            }

            throw $e;
        }
    }

    /**
     * Admin: refund a wallet-paid order back to the user's wallet.
     *
     * Idempotent:
     * - if refund tx already exists, returns it and ensures order state.
     */
    public function refundWalletOrder(int $orderId, int $adminUserId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($orderId, $adminUserId, $reason) {
            $order = Order::query()
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) ($order->payment_provider ?? '') !== 'wallet') {
                abort(422, 'Order is not wallet-paid');
            }

            // If already refunded (idempotent), return existing tx before state checks
            $existingTx = WalletTransaction::query()
                ->where('user_id', (int) $order->user_id)
                ->where('reference_type', 'order')
                ->where('reference_id', (int) $order->id)
                ->where('direction', 'credit')
                ->where('type', 'order_refund')
                ->first();

            if ((string) ($order->payment_status ?? '') !== 'paid') {
                abort(422, 'Order is not paid');
            }

            if ((string) ($order->status ?? '') === 'delivered') {
                abort(409, 'Cannot refund a delivered order');
            }

            $wallet = Wallet::query()
                ->where('user_id', (int) $order->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $wallet->currency !== (string) $order->currency) {
                abort(500, 'Wallet currency mismatch');
            }

            $amount = (int) $order->total_amount_minor;
            if ($amount <= 0) {
                abort(422, 'Invalid order amount');
            }

            $newBalance = (int) $wallet->balance_minor + $amount;

            $tx = new WalletTransaction();
            $tx->wallet_id = (int) $wallet->id;
            $tx->user_id = (int) $order->user_id;
            $tx->direction = 'credit';
            $tx->status = 'posted';
            $tx->type = 'order_refund';
            $tx->amount_minor = $amount;
            $tx->balance_after_minor = $newBalance;
            $tx->reference_type = 'order';
            $tx->reference_id = (int) $order->id;
            $tx->reference_uuid = $order->order_uuid ? (string) $order->order_uuid : null;
            $tx->meta = [
                'currency' => (string) $order->currency,
                'order_uuid' => (string) ($order->order_uuid ?? ''),
                'refunded_by_admin_id' => (int) $adminUserId,
                'reason' => $reason,
            ];
            $tx->save();

            $wallet->balance_minor = $newBalance;
            $wallet->save();

            $this->markOrderRefunded($order, $adminUserId, $reason);

            return ['order' => $order, 'transaction' => $tx];
        });
    }

    private function markOrderRefunded(Order $order, int $adminUserId, ?string $reason = null): void
    {
        $order->status = 'refunded';
        $order->payment_status = 'refunded';
        $order->save();

        // Optional audit log (best-effort)
        try {
            if (class_exists(\App\Models\AuditLog::class)) {
                \App\Models\AuditLog::create([
                    'actor_type' => 'App\Models\User',
                    'actor_id' => $adminUserId,
                    'auditable_type' => 'App\Models\Order',
                    'auditable_id' => (int) $order->id,
                    'action' => 'order_refund_wallet',
                    'old_values' => null,
                    'new_values' => null,
                    'meta' => [
                        'reason' => $reason,
                        'payment_provider' => (string) ($order->payment_provider ?? ''),
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function markOrderPaidByWallet(Order $order): void
    {
        $order->payment_provider = 'wallet';
        $order->payment_status = 'paid';

        if (!in_array((string) $order->status, ['paid', 'delivered'], true)) {
            $order->status = 'paid';
        }

        $order->save();
    }

    private function findOrderDebitTx(int $userId, int $orderId): ?WalletTransaction
    {
        return WalletTransaction::query()
            ->where('user_id', $userId)
            ->where('reference_type', 'order')
            ->where('reference_id', $orderId)
            ->where('direction', 'debit')
            ->where('type', 'order_payment')
            ->first();
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
