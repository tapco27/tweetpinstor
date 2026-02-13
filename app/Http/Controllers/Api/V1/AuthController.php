<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
            'user' => (new UserResource($user))->resolve(request()),
        ]);
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
            'user' => (new UserResource($user))->resolve(request()),
        ]);
    }
}
