<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    public function export(Request $request): JsonResponse
    {
        $user = $request->user()->load(['analyses.report', 'subscription']);

        $export = [
            'exported_at' => now()->toISOString(),
            'account'     => [
                'name'       => $user->name,
                'email'      => $user->email,
                'created_at' => $user->created_at,
                'plan'       => $user->plan->name,
            ],
            'analyses' => $user->analyses->map(function ($a) {
                return [
                    'id'            => $a->id,
                    'source_type'   => $a->source_type,
                    'message_count' => $a->message_count,
                    'status'        => $a->status,
                    'created_at'    => $a->created_at,
                    'report'        => $a->report ? [
                        'chemistry_score' => $a->report->chemistry_score,
                        'common_ground'   => $a->report->common_ground,
                        'memory_box'      => $a->report->memory_box,
                        'icebreakers'     => $a->report->icebreakers,
                    ] : null,
                ];
            }),
        ];

        return response()->json(['success' => true, 'data' => $export]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();
        $user->delete(); // soft delete; hard purge via scheduled job after 30 days
        return response()->json(['success' => true, 'data' => null]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale'           => ['sometimes', 'string', 'max:10'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
        ]);

        $request->user()->update($data);
        return response()->json(['success' => true, 'data' => $request->user()]);
    }
}
