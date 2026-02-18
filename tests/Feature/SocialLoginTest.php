<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_social_login_with_email_creates_user_and_social_account(): void
    {
        $this->mockGoogleVerifier([
            'provider_user_id' => 'google-sub-1',
            'email' => 'user1@example.com',
            'email_verified' => true,
            'name' => 'User One',
            'payload' => ['sub' => 'google-sub-1'],
        ]);

        $response = $this->postJson('/api/v1/auth/google', [
            'idToken' => str_repeat('a', 60),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.needsAccountCompletion', false)
            ->assertJsonPath('data.accountCompletionPolicy.requireEmail', true)
            ->assertJsonPath('data.user.email', 'user1@example.com');

        $user = User::query()->where('email', 'user1@example.com')->first();
        $this->assertNotNull($user);

        $this->assertDatabaseHas('social_accounts', [
            'provider' => 'google',
            'provider_user_id' => 'google-sub-1',
            'user_id' => $user->id,
            'email' => 'user1@example.com',
        ]);
    }

    public function test_social_login_without_email_creates_placeholder_user_and_requires_completion(): void
    {
        $this->mockGoogleVerifier([
            'provider_user_id' => 'google-sub-no-email',
            'email' => null,
            'email_verified' => false,
            'name' => null,
            'payload' => ['sub' => 'google-sub-no-email'],
        ]);

        $response = $this->postJson('/api/v1/auth/google', [
            'idToken' => str_repeat('b', 60),
            'name' => 'Fallback Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.needsAccountCompletion', true)
            ->assertJsonPath('data.accountCompletionPolicy.requireEmail', true);

        $userId = (int) $response->json('data.user.id');
        $user = User::query()->findOrFail($userId);

        $this->assertMatchesRegularExpression(
            '/^social\+google_[a-f0-9]{32}@social\.placeholder\.local$/',
            (string) $user->email
        );

        $this->assertDatabaseHas('social_accounts', [
            'provider' => 'google',
            'provider_user_id' => 'google-sub-no-email',
            'user_id' => $user->id,
            'email' => null,
        ]);
    }

    public function test_social_login_links_to_existing_user_by_email(): void
    {
        $existing = User::factory()->create([
            'email' => 'linked@example.com',
            'name' => 'Existing User',
        ]);

        $this->mockGoogleVerifier([
            'provider_user_id' => 'google-sub-link-1',
            'email' => 'linked@example.com',
            'email_verified' => true,
            'name' => 'Other Name',
            'payload' => ['sub' => 'google-sub-link-1'],
        ]);

        $response = $this->postJson('/api/v1/auth/google', [
            'idToken' => str_repeat('c', 60),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', (string) $existing->id)
            ->assertJsonPath('data.user.email', 'linked@example.com');

        $this->assertDatabaseHas('social_accounts', [
            'provider' => 'google',
            'provider_user_id' => 'google-sub-link-1',
            'user_id' => $existing->id,
            'email' => 'linked@example.com',
        ]);

        $this->assertSame(1, SocialAccount::query()
            ->where('provider', 'google')
            ->where('provider_user_id', 'google-sub-link-1')
            ->count());
    }

    private function mockGoogleVerifier(array $info): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, new class($info) extends GoogleIdTokenVerifier {
            public function __construct(private array $info)
            {
            }

            public function verify(string $idToken): array
            {
                return $this->info;
            }
        });
    }
}
