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

        // Idempotency fast path (topup_uuid unique globally, but keep user-friendly)
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
                // Defensive idempotency inside tx
                $already = WalletTopup::query()
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
        } catch (\Throwable $e) {
            // If DB fails, delete file
            try {
                Storage::disk('local')->delete($path);
            } catch (\Throwable $_) {}
            throw $e;
        }

        return $this->ok(new WalletTopupResource($topup), [], 201);
    }
}
