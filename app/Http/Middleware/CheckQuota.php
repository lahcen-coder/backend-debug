<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckQuota
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasReachedTokenLimit()) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => [
                    'code'    => 'TOKEN_BUDGET_EXCEEDED',
                    'message' => 'Monthly AI token budget reached. Please upgrade your plan.',
                ],
            ], 429);
        }

        return $next($request);
    }
}
