<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $throttleKey = 'admin-login:'.mb_strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => ['Too many login attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.'],
            ])->status(429);
        }

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 300);

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->is_active) {
            RateLimiter::hit($throttleKey, 300);

            throw ValidationException::withMessages([
                'email' => ['This account is disabled.'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        $token = $user->createToken($data['device_name'] ?? 'admin-panel');

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => (new UserResource($user))->toArray($request),
            // Optional modules of this deployment: the panel hides whole
            // sections on these, so it must not have to guess (config/features.php).
            'features' => FeatureFlags::all(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => (new UserResource($request->user()))->toArray($request),
            'features' => FeatureFlags::all(),
        ]);
    }
}
