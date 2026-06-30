<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Register a new user account.
     *
     * Creates a free-tier account, fires the Registered event (triggers
     * the email verification notification), and returns a Sanctum token
     * so the client can make authenticated requests immediately.
     *
     * POST /api/v1/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name'             => $request->name,
            'email'            => $request->email,
            'password'         => Hash::make($request->password),
            'membership'       => 'free',
            'locale'           => $request->input('locale', app()->getLocale()),
            'marketing_opt_in' => (bool) $request->input('marketing_opt_in', false),
            'consent_version'  => config('app.consent_version', '1.0'),
            'consented_at'     => now(),
        ]);

        try {
            event(new Registered($user));
        } catch (\Throwable $e) {
            // Email sending is non-blocking — account is still created.
            \Illuminate\Support\Facades\Log::warning('Registration email failed: ' . $e->getMessage());
        }

        $tokenName = $request->input('device_name', 'api-token');
        $token     = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => [
                'user'  => $user,
                'token' => $token,
            ],
            'message' => 'Account created. Please verify your email address.',
        ], 201);
    }

    /**
     * Authenticate an existing user and issue a Sanctum token.
     *
     * POST /api/v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => [
                    'code'    => 'INVALID_CREDENTIALS',
                    'message' => 'The email or password is incorrect.',
                ],
            ], 401);
        }

        /** @var User $user */
        $user      = Auth::user();
        $tokenName = $request->input('device_name', 'api-token');
        $token     = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => [
                'user'  => $user,
                'token' => $token,
            ],
        ]);
    }

    /**
     * Revoke the current access token (logout from this device).
     *
     * To log out of all devices, call DELETE /api/v1/auth/logout-all (not yet implemented).
     *
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Return the authenticated user's profile + current usage stats.
     *
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $request->user()->load('plan');
        $usage = $user->currentUsage();

        return response()->json([
            'success' => true,
            'data'    => [
                'user'  => $user,
                'usage' => [
                    'period'                   => $usage->period,
                    'analyses_used'            => $usage->analyses_used,
                    'analyses_limit'           => $user->plan->monthly_analyses,
                    'tokens_used'              => $usage->tokens_used,
                    'token_budget'             => $user->plan->monthly_token_budget,
                    'assistant_messages_used'  => $usage->assistant_messages_used,
                    'assistant_messages_limit' => $user->plan->monthly_assistant_messages,
                ],
            ],
        ]);
    }
}
