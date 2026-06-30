<?php

namespace App\Http\Controllers;

use App\Models\Analysis;
use App\Models\SharedAccess;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SharedAccessController extends Controller
{
    public function share(Request $request, Analysis $analysis): JsonResponse
    {
        if ($analysis->user_id !== $request->user()->id) abort(403);

        $data = $request->validate(['email' => ['required', 'email']]);

        if (! $request->user()->plan->hasFeature('sharing')) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => ['code' => 'PLAN_REQUIRED', 'message' => 'Sharing requires a Plus or Premium plan.'],
            ], 403);
        }

        $shared = SharedAccess::updateOrCreate(
            ['analysis_id' => $analysis->id, 'invite_email' => $data['email']],
            [
                'owner_user_id' => $request->user()->id,
                'invite_token'  => SharedAccess::generateToken(),
                'status'        => 'pending',
            ]
        );

        // TODO: Mail::to($data['email'])->send(new ShareInviteMail($shared, $request->user()));

        return response()->json(['success' => true, 'data' => $shared]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $shared = SharedAccess::where('invite_token', $token)->where('status', 'pending')->firstOrFail();
        $shared->update([
            'status'             => 'accepted',
            'shared_with_user_id' => $request->user()->id,
            'accepted_at'        => now(),
        ]);
        return response()->json(['success' => true, 'data' => $shared->load('analysis')]);
    }

    public function sharedWithMe(Request $request): JsonResponse
    {
        $shared = SharedAccess::with('analysis')
            ->where('shared_with_user_id', $request->user()->id)
            ->where('status', 'accepted')
            ->latest()
            ->get();
        return response()->json(['success' => true, 'data' => $shared]);
    }

    public function revoke(Request $request, SharedAccess $shared): JsonResponse
    {
        if ($shared->owner_user_id !== $request->user()->id) abort(403);
        $shared->update(['status' => 'revoked']);
        return response()->json(['success' => true, 'data' => null]);
    }
}
