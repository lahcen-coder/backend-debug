<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:100'],
            'email'                 => ['required', 'email', 'max:255', 'unique:users'],
            'password'              => ['required', 'confirmed', Password::min(8)],
            'marketing_opt_in'      => ['boolean'],
        ]);

        $freePlan = Plan::where('slug', 'free')->firstOrFail();

        $user = User::create([
            'name'             => $data['name'],
            'email'            => $data['email'],
            'password'         => Hash::make($data['password']),
            'plan_id'          => $freePlan->id,
            'marketing_opt_in' => $data['marketing_opt_in'] ?? false,
            'consent_version'  => '1.0',
            'consented_at'     => now(),
        ]);

        event(new Registered($user));

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => ['user' => $user->load('plan'), 'token' => $token],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($data)) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => ['code' => 'INVALID_CREDENTIALS', 'message' => 'The provided credentials are incorrect.'],
            ], 401);
        }

        $user  = Auth::user();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => ['user' => $user->load('plan'), 'token' => $token],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'data' => null]);
    }

    public function me(Request $request): JsonResponse
    {
        $user  = $request->user()->load('plan');
        $usage = $user->currentUsage();

        return response()->json([
            'success' => true,
            'data'    => array_merge($user->toArray(), ['usage' => $usage]),
        ]);
    }
}
