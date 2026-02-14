<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateWalletTopupRequest;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WalletTopupResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\PaymentMethod;
use App\Models\Wallet;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WalletController extends Controller
{
    use ApiResponse;

    public function show()
    {
        $user = auth('api')->user();
        $currency = (string) app('user_currency');

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => $currency, 'balance_minor' => 0]
        );

        if ($wallet->currency !== $currency) {
            return $this->fail('Wallet currency mismatch', 500);
        }

        return $this->ok(new WalletResource($wallet));
    }

    public function transactions()
    {
        $user = auth('api')->user();
        $currency = (string) app('user_currency');

        $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();
        if ($wallet->currency !== $currency) {
            return $this->fail('Wallet currency mismatch', 500);
        }

        $limit = (int) request('limit', 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;

        $p = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($limit);

        return $this->ok(
            WalletTransactionResource::collection($p->getCollection()),
            $this->paginationMeta($p)
        );
    }

    public function topups()
    {
        $user = auth('api')->user();
        $currency = (string) app('user_currency');

        $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();
        if ($wallet->currency !== $currency) {
            return $this->fail('Wallet currency mismatch', 500);
        }

        $limit = (int) request('limit', 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;

        $p = WalletTopup::query()
            ->where('wallet_id', $wallet->id)
            ->where('user_id', $user->id)
            ->with('paymentMethod')
            ->orderByDesc('id')
            ->paginate($limit);

        return $this->ok(
            WalletTopupResource::collection($p->getCollection()),
            $this->paginationMeta($p)
        );
    }

    /**
     * Manual topup with receipt (image + note)
     * multipart/form-data
     */
    public function createTopup(CreateWalletTopupRequest $request)
    {
        $user = auth('api')->user();
        $currency = (string) app('user_currency');

        // Payment method must be active and allowed for topup + currency
        $pm = PaymentMethod::query()
            ->whereKey((int) $request->payment_method_id)
            ->where('is_active', true)
            ->whereIn('scope', ['topup', 'both'])
            ->where(function ($x) use ($currency) {
                $x->whereNull('currency')->orWhere('currency', $currency);
            })
            ->first();

        if (!$pm) {
            return $this->fail('Payment method not available', 422);
        }

        $topupUuid = (string) $request->topup_uuid;

        // ✅ Idempotency fast path (same user only)
        $existing = WalletTopup::query()
            ->where('user_id', $user->id)
            ->where('topup_uuid', $topupUuid)
            ->with('paymentMethod')
            ->first();

        if ($existing) {
            return $this->ok(new WalletTopupResource($existing));
        }

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => $currency, 'balance_minor' => 0]
        );

        if ($wallet->currency !== $currency) {
            return $this->fail('Wallet currency mismatch', 500);
        }

        // Store receipt image in private disk (local => storage/app/private)
        $file = $request->file('receipt_image');
        $path = $file ? $file->store("wallet_topups/{$topupUuid}", 'local') : null;

        if (!$path) {
            return $this->fail('Receipt upload failed', 422);
        }

        try {
            $topup = DB::transaction(function () use ($user, $wallet, $pm, $currency, $request, $topupUuid, $path) {

                // ✅ Critical fix: check existing by (user_id, topup_uuid) ONLY
                $already = WalletTopup::query()
                    ->where('user_id', $user->id)
                    ->where('topup_uuid', $topupUuid)
                    ->lockForUpdate()
                    ->first();

                if ($already) {
                    return $already->load('paymentMethod');
                }

                $t = new WalletTopup();
                $t->topup_uuid = $topupUuid;
                $t->wallet_id = $wallet->id;
                $t->user_id = $user->id;
                $t->payment_method_id = $pm->id;

                $t->currency = $currency;
                $t->amount_minor = (int) $request->amount_minor;

                $t->status = 'pending_review';

                $t->payer_full_name = (string) $request->payer_full_name;
                $t->national_id = (string) $request->national_id;
                $t->phone = (string) $request->phone;

                $t->receipt_note = (string) $request->receipt_note;
                $t->receipt_image_path = (string) $path;

                $t->save();

                return $t->load('paymentMethod');
            });
        } catch (QueryException $e) {
            // ✅ Always delete uploaded file on collision/failure to avoid orphans
            try { Storage::disk('local')->delete($path); } catch (\Throwable $_) {}

            $sqlState = $e->errorInfo[0] ?? $e->getCode();

            // Postgres unique violation: 23505 (MySQL common: 23000)
            if (in_array((string) $sqlState, ['23505', '23000'], true)) {

                // If it's ours => return existing (idempotency under race)
                $mine = WalletTopup::query()
                    ->where('user_id', $user->id)
                    ->where('topup_uuid', $topupUuid)
                    ->with('paymentMethod')
                    ->first();

                if ($mine) {
                    return $this->ok(new WalletTopupResource($mine));
                }

                // Otherwise => conflict WITHOUT leaking data
                $exists = WalletTopup::query()
                    ->where('topup_uuid', $topupUuid)
                    ->exists();

                if ($exists) {
                    return $this->fail('Topup UUID conflict', 409);
                }
            }

            throw $e;
        } catch (\Throwable $e) {
            try { Storage::disk('local')->delete($path); } catch (\Throwable $_) {}
            throw $e;
        }

        // ✅ If tx returned existing topup (race), remove this request’s uploaded file (orphan cleanup)
        if ((string) ($topup->receipt_image_path ?? '') !== (string) $path) {
            try { Storage::disk('local')->delete($path); } catch (\Throwable $_) {}
        }

        return $this->ok(new WalletTopupResource($topup), [], 201);
    }
}
