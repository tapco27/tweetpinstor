<?php

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            Wallet::query()
                ->with(['user:id,currency', 'transactions:id,wallet_id', 'topups:id,wallet_id'])
                ->chunkById(200, function ($wallets): void {
                    foreach ($wallets as $wallet) {
                        $user = $wallet->user;
                        $hasActivity = $wallet->balance_minor > 0
                            || $wallet->transactions->isNotEmpty()
                            || $wallet->topups->isNotEmpty();

                        if (!$user) {
                            if (!$hasActivity) {
                                $wallet->delete();
                            }

                            continue;
                        }

                        if (empty($user->currency)) {
                            if (!$hasActivity) {
                                $wallet->delete();
                            }

                            continue;
                        }

                        if (strtoupper((string) $wallet->currency) !== strtoupper((string) $user->currency) && !$hasActivity) {
                            $wallet->currency = strtoupper((string) $user->currency);
                            $wallet->save();
                        }
                    }
                });

            User::query()
                ->whereNotNull('currency')
                ->whereDoesntHave('wallet')
                ->chunkById(200, function ($users): void {
                    foreach ($users as $user) {
                        $user->wallet()->create([
                            'currency' => strtoupper((string) $user->currency),
                            'balance_minor' => 0,
                        ]);
                    }
                });
        });
    }

    public function down(): void
    {
        // Cleanup migration only; no down action.
    }
};
