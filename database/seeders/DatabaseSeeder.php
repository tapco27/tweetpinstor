<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoCatalogSeeder::class);

        DB::transaction(function () {
            User::where('email', 'test@example.com')->delete();

            $user = User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => Hash::make('Password123!'),
                'currency' => 'TRY',
                'currency_selected_at' => now(),
            ]);

            $user->wallet()->create([
                'currency' => $user->currency,
                'balance_minor' => 0,
            ]);
        });
    }
}
