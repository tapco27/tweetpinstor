<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Approve + Post (credit) topup in ONE atomic transaction.
     * Idempotent: if already posted => returns existing tx.
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

            $tx->amount_minor = (int) $topup->amount_minor;
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
}
