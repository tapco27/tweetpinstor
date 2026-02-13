<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoCatalogSeeder::class);

        User::where('email', 'test@example.com')->delete();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('Password123!'),
            'currency' => 'TRY',
            'currency_selected_at' => now(),
        ]);
    }
}
