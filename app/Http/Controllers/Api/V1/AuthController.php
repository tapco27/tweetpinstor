<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\SocialLoginRequest;
use App\Http\Resources\UserResource;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Social\AppleIdTokenVerifier;
use App\Services\Social\GoogleIdTokenVerifier;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * Email/password register
     * - currency OPTIONAL (TRY|SYP)
     * - wallet created ONLY if currency was selected
     *
     * @unauthenticated
     */
    public function register(RegisterRequest $request)
    {
        $currency = null;
        if ($request->filled('currency')) {
            $currency = strtoupper((string) $request->input('currency'));
        }

        $user = DB::transaction(function () use ($request, $currency) {
            $user = new User();
            $user->name = (string) $request->input('name');
            $user->email = (string) $request->input('email');
            $user->password = Hash::make((string) $request->input('password'));

            if ($currency && in_array($currency, ['TRY', 'SYP'], true)) {
                $user->currency = $currency;
                $user->currency_selected_at = now();
            }

            $user->save();

            // Wallet فقط إذا العملة موجودة
            if (!empty($user->currency)) {
                Wallet::firstOrCreate(
                    ['user_id' => $user->id],
                    ['currency' => $user->currency, 'balance_minor' => 0]
                );
            }

            return $user;
        });

        $token = auth('api')->login($user);

        return $this->ok([
            'token' => $token,
            'tokenType' => 'bearer',
            'expiresIn' => auth('api')->factory()->getTTL() * 60,
            'needsCurrencySelection' => empty($user->currency),
            'user' => (new UserResource($user))->resolve(request()),
        ], [], 201);
    }

    /**
     * @unauthenticated
     */
    public function login(LoginRequest $request)
    {
        $credentials = $request->only('email', 'password');

        if (! $token = auth('api')->attempt($credentials)) {
            return $this->fail('Invalid credentials', 401);
        }

        $user = auth('api')->user();

        return $this->ok([
            'token' => $token,
            'tokenType' => 'bearer',
            'expiresIn' => auth('api')->factory()->getTTL() * 60,
            'needsCurrencySelection' => empty($user->currency),
            'user' => (new UserResource($user))->resolve(request()),
        ]);
    }

    /**
     * Social login: Google
     *
     * @unauthenticated
     */
    public function loginWithGoogle(SocialLoginRequest $request, GoogleIdTokenVerifier $verifier)
    {
        try {
            $info = $verifier->verify((string) $request->input('idToken'));
        } catch (\Throwable $e) {
            return $this->fail('Invalid Google token', 401);
        }

        return $this->handleSocialLogin('google', $info, (string) $request->input('name'));
    }

    /**
     * Social login: Apple (iCloud)
     *
     * @unauthenticated
     */
    public function loginWithApple(SocialLoginRequest $request, AppleIdTokenVerifier $verifier)
    {
        try {
            $info = $verifier->verify((string) $request->input('idToken'));
        } catch (\Throwable $e) {
            return $this->fail('Invalid Apple token', 401);
        }

        return $this->handleSocialLogin('apple', $info, (string) $request->input('name'));
    }

    private function handleSocialLogin(string $provider, array $info, string $nameFromClient = '')
    {
        $providerUserId = (string) ($info['provider_user_id'] ?? '');
        if ($providerUserId === '') {
            return $this->fail('Invalid social payload', 401);
        }

        $email = $info['email'] ?? null;
        $email = is_string($email) && trim($email) !== '' ? trim($email) : null;

        $name = $info['name'] ?? null;
        $name = is_string($name) && trim($name) !== '' ? trim($name) : null;
        if (!$name && trim($nameFromClient) !== '') {
            $name = trim($nameFromClient);
        }

        $emailVerified = (bool) ($info['email_verified'] ?? false);
        $payload = is_array($info['payload'] ?? null) ? $info['payload'] : null;

        $emailWasMissing = !$email;

        try {
            $user = DB::transaction(function () use ($provider, $providerUserId, $email, $name, $emailVerified, $payload) {
                // 1) إذا الحساب الاجتماعي موجود -> رجّع اليوزر
                $account = SocialAccount::query()
                    ->where('provider', $provider)
                    ->where('provider_user_id', $providerUserId)
                    ->with('user')
                    ->first();

                if ($account && $account->user) {
                    // Enrich email verification (اختياري)
                    if ($email && $emailVerified && empty($account->user->email_verified_at) && $account->user->email === $email) {
                        $account->user->email_verified_at = now();
                        $account->user->save();
                    }
                    return $account->user;
                }

                // 2) الربط عبر الإيميل إذا موجود
                $user = null;
                if ($email) {
                    $user = User::query()->where('email', $email)->first();
                }

                // 3) إنشاء User إذا غير موجود
                if (!$user) {
                    $resolvedEmail = $email ?: $this->buildPlaceholderEmail($provider, $providerUserId);

                    $user = new User();
                    $user->name = $name ?: $this->fallbackNameFromEmail($resolvedEmail);
                    $user->email = $resolvedEmail;
                    $user->password = Hash::make(Str::random(40));

                    if ($email && $emailVerified) {
                        $user->email_verified_at = now();
                    }

                    // IMPORTANT: Social login ينشئ user بدون currency
                    $user->currency = null;
                    $user->currency_selected_at = null;

                    $user->save();
                } else {
                    // Enrich name إذا ناقص
                    if (empty($user->name) && $name) {
                        $user->name = $name;
                    }

                    // Verify email إذا صار verified
                    if ($email && $emailVerified && empty($user->email_verified_at) && $user->email === $email) {
                        $user->email_verified_at = now();
                    }

                    if ($user->isDirty(['name', 'email_verified_at'])) {
                        $user->save();
                    }
                }

                // 4) إنشاء/تحديث social account
                SocialAccount::updateOrCreate(
                    [
                        'provider' => $provider,
                        'provider_user_id' => $providerUserId,
                    ],
                    [
                        'user_id' => $user->id,
                        'email' => $email,
                        'payload' => $payload,
                    ]
                );

                return $user;
            });
        } catch (\Throwable $e) {
            return $this->fail('Social login failed', 401);
        }

        $token = auth('api')->login($user);

        $emailCompletionRequiredByPolicy = $this->isSocialEmailCompletionRequired();
        $needsAccountCompletion = $this->isPlaceholderEmail($user->email)
            && ($emailCompletionRequiredByPolicy || $emailWasMissing);

        return $this->ok([
            'token' => $token,
            'tokenType' => 'bearer',
            'expiresIn' => auth('api')->factory()->getTTL() * 60,
            'needsCurrencySelection' => empty($user->currency),
            'needsAccountCompletion' => $needsAccountCompletion,
            'accountCompletionPolicy' => [
                'requireEmail' => $emailCompletionRequiredByPolicy,
            ],
            'user' => (new UserResource($user))->resolve(request()),
        ]);
    }

    public function me()
    {
        return $this->ok(new UserResource(auth('api')->user()));
    }

    /**
     * Set currency once after login (Popup flow)
     * Creates wallet if missing
     */
    public function setCurrency(Request $request)
    {
        $data = $request->validate([
            'currency' => ['required', 'in:TRY,SYP'],
        ]);

        $userId = (int) auth('api')->id();

        return DB::transaction(function () use ($userId, $data) {
            $user = User::query()
                ->whereKey($userId)
                ->lockForUpdate()
                ->firstOrFail();

            if (!empty($user->currency)) {
                return $this->fail('Currency already set', 409);
            }

            $user->currency = strtoupper((string) $data['currency']);
            $user->currency_selected_at = now();
            $user->save();

            $wallet = Wallet::firstOrCreate(
                ['user_id' => $user->id],
                ['currency' => $user->currency, 'balance_minor' => 0]
            );

            // لو الـ wallet موجودة وعملتها مختلفة (حالات قديمة) سوّيها sync
            if (!empty($wallet->currency) && $wallet->currency !== $user->currency) {
                $wallet->currency = $user->currency;
                $wallet->save();
            }

            return $this->ok(new UserResource($user));
        });
    }

    public function logout()
    {
        auth('api')->logout();
        return $this->ok(['message' => 'Logged out']);
    }

    public function refresh()
    {
        $token = auth('api')->refresh();
        $user = auth('api')->user();

        return $this->ok([
            'token' => $token,
            'tokenType' => 'bearer',
            'expiresIn' => auth('api')->factory()->getTTL() * 60,
            'needsCurrencySelection' => empty($user->currency),
            'user' => (new UserResource($user))->resolve(request()),
        ]);
    }

    private function buildPlaceholderEmail(string $provider, string $providerUserId): string
    {
        $providerSlug = preg_replace('/[^a-z0-9]/', '', strtolower($provider)) ?: 'social';
        $userSlug = substr(hash('sha256', strtolower($provider) . '|' . $providerUserId), 0, 32);

        return sprintf('social+%s_%s@social.placeholder.local', $providerSlug, $userSlug);
    }

    private function isPlaceholderEmail(?string $email): bool
    {
        if (!is_string($email) || $email === '') {
            return false;
        }

        return str_starts_with($email, 'social+') && str_ends_with($email, '@social.placeholder.local');
    }

    private function isSocialEmailCompletionRequired(): bool
    {
        return (bool) config('auth.social.require_email_completion', true);
    }

    private function fallbackNameFromEmail(string $email): string
    {
        $part = explode('@', $email)[0] ?? 'User';
        $part = preg_replace('/[^a-zA-Z0-9._-]/', ' ', $part);
        $part = trim((string) $part);

        return $part !== '' ? $part : 'User';
    }
}
