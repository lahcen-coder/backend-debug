<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Api\V1\AnalysisController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\SharedAccessController;
use Illuminate\Support\Facades\Route;

/*
|──────────────────────────────────────────────────────────────────────────────
| API Routes — /api/v1
|
| All routes live under the /api/v1 prefix (configured in bootstrap/app.php).
| Authentication uses Laravel Sanctum personal access tokens.
| Standard JSON envelope: { success, data, error?, message? }
|──────────────────────────────────────────────────────────────────────────────
*/

Route::prefix('v1')->group(function () {

    // ── Health Check (unauthenticated) ───────────────────────────────────────
    Route::get('ping', fn () => response()->json(['success' => true, 'data' => ['status' => 'ok']]));

    // ── Debug: config check (remove in production) ────────────────────────────
    Route::get('debug-config', function () {
        return response()->json([
            'app_env'        => config('app.env'),
            'app_url'        => config('app.url'),
            'frontend_url'   => config('app.frontend_url', env('FRONTEND_URL')),
            'cors_origins'   => config('cors.allowed_origins'),
            'db_host'        => config('database.connections.mysql.host'),
            'db_name'        => config('database.connections.mysql.database'),
            'redis_host'     => config('database.redis.default.host'),
            'mail_mailer'    => config('mail.default'),
            'queue_driver'   => config('queue.default'),
        ]);
    });

    // ── Public: Authentication ────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:10,1');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:10,1');

        Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])
            ->middleware('throttle:5,1');

        Route::post('reset-password', [PasswordResetController::class, 'reset'])
            ->middleware('throttle:5,1');
    });

    // ── Public: Plans ─────────────────────────────────────────────────────────
    Route::get('plans', [BillingController::class, 'plans']);

    // ── Public: Stripe Webhooks ───────────────────────────────────────────────
    // No Sanctum auth — signature verified inside BillingController::webhook()
    Route::post('webhooks/stripe', [BillingController::class, 'webhook'])
        ->middleware('throttle:60,1');

    // ── Authenticated ─────────────────────────────────────────────────────────
    Route::middleware(['auth:sanctum'])->group(function () {

        // Auth
        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });

        // Analyses
        Route::prefix('analyses')->group(function () {
            Route::get('/', [AnalysisController::class, 'index']);
            Route::post('/', [AnalysisController::class, 'store'])
                ->middleware(['App\Http\Middleware\CheckQuota', 'throttle:30,60']);
            Route::get('/{analysis}', [AnalysisController::class, 'show']);
            Route::get('/{analysis}/report', [AnalysisController::class, 'report']);
            Route::delete('/{analysis}', [AnalysisController::class, 'destroy']);
            Route::post('/{analysis}/share', [SharedAccessController::class, 'share']);
        });

        // Shared Access
        Route::prefix('shared')->group(function () {
            Route::get('/', [SharedAccessController::class, 'sharedWithMe']);
            Route::post('/accept/{token}', [SharedAccessController::class, 'accept']);
            Route::delete('/{shared}', [SharedAccessController::class, 'revoke']);
        });

        // Billing / Stripe Customer Portal
        Route::prefix('billing')->group(function () {
            Route::post('checkout', [BillingController::class, 'checkout']);
            Route::post('portal', [BillingController::class, 'portal']);
        });

        // Account & GDPR
        Route::prefix('account')->group(function () {
            Route::get('export', [AccountController::class, 'export']);
            Route::delete('/', [AccountController::class, 'destroy']);
            Route::patch('preferences', [AccountController::class, 'updatePreferences']);
        });
    });
});
