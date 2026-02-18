<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthCurrencySelectionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_does_not_require_currency_and_marks_user_for_selection(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'flow@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.needsCurrencySelection', true);

        $user = User::query()->where('email', 'flow@example.com')->firstOrFail();

        $this->assertNull($user->currency);
        $this->assertNull($user->currency_selected_at);
        $this->assertDatabaseMissing('wallets', ['user_id' => $user->id]);
    }

    public function test_currency_can_be_set_once_after_login_and_creates_wallet(): void
    {
        $user = User::factory()->create([
            'currency' => null,
            'currency_selected_at' => null,
            'password' => bcrypt('password123'),
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $loginResponse->assertOk();
        $loginResponse->assertJsonPath('data.needsCurrencySelection', true);

        $token = $loginResponse->json('data.token');

        $setCurrencyResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/me/currency', [
                'currency' => 'TRY',
            ]);

        $setCurrencyResponse->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'currency' => 'TRY',
        ]);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'currency' => 'TRY',
            'balance_minor' => 0,
        ]);

        $secondSetResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/me/currency', [
                'currency' => 'SYP',
            ]);

        $secondSetResponse->assertStatus(409);
    }
}
