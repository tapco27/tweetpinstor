<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\LoginRequest;
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
     * @unauthenticated
     */
    public function register(RegisterRequest $request)
    {
        $user = DB::transaction(function () use ($request) {
            $user = new User();
            $user->name = $request->name;
            $user->email = $request->email;
            $user->password = Hash::make($request->password);

            $user->currency = strtoupper($request->currency);
            $user->currency_selected_at = now();

            $user->save();

            // إنشاء Wallet مباشرة بعد إنشاء المستخدم
            $user->wallet()->create([
                'currency' => $user->currency,
                'balance_minor' => 0,
            ]);

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
        if (!$token = auth('api')->attempt($credentials)) {
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

        try {
            $user = DB::transaction(function () use ($provider, $providerUserId, $email, $name, $emailVerified, $payload) {
                // 1) If social account exists -> return its user
                $account = SocialAccount::query()
                    ->where('provider', $provider)
                    ->where('provider_user_id', $providerUserId)
                    ->with('user')
                    ->first();

                if ($account && $account->user) {
                    return $account->user;
                }

                // 2) Try to link by email (if available)
                $user = null;
                if ($email) {
                    $user = User::query()->where('email', $email)->first();
                }

                // 3) Create user if needed
                if (!$user) {
                    if (!$email) {
                        // Without email we cannot create a new user safely
                        throw new \RuntimeException('Missing email');
                    }

                    $user = new User();
                    $user->name = $name ?: $this->fallbackNameFromEmail($email);
                    $user->email = $email;
                    $user->password = Hash::make(Str::random(40));

                    if ($emailVerified) {
                        $user->email_verified_at = now();
                    }

                    // IMPORTANT: currency stays NULL for social login.
                    $user->save();
                } else {
                    // If existing user has empty name, enrich it
                    if (empty($user->name) && $name) {
                        $user->name = $name;
                        $user->save();
                    }
                }

                // 4) Create social account record
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

        return $this->ok([
            'token' => $token,
            'tokenType' => 'bearer',
            'expiresIn' => auth('api')->factory()->getTTL() * 60,
            'needsCurrencySelection' => empty($user->currency),
            'user' => (new UserResource($user))->resolve(request()),
        ]);
    }

    private function fallbackNameFromEmail(string $email): string
    {
        $part = explode('@', $email)[0] ?? 'User';
        $part = preg_replace('/[^a-zA-Z0-9._-]/', ' ', $part);
        $part = trim((string) $part);
        return $part !== '' ? $part : 'User';
    }

    public function me()
    {
        return $this->ok(new UserResource(auth('api')->user()));
    }

    public function setCurrency(Request $request)
    {
        $data = $request->validate([
            'currency' => ['required', 'in:TRY,SYP'],
        ]);

        $userId = auth('api')->id();

        return DB::transaction(function () use ($userId, $data) {
            $user = User::query()
                ->whereKey($userId)
                ->lockForUpdate()
                ->firstOrFail();

            if (!empty($user->currency)) {
                return $this->fail('Currency already set', 409);
            }

            $user->currency = strtoupper($data['currency']);
            $user->currency_selected_at = now();
            $user->save();

            // إنشاء Wallet إذا غير موجود
            Wallet::firstOrCreate(
                ['user_id' => $user->id],
                ['currency' => $user->currency, 'balance_minor' => 0]
            );

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
}
