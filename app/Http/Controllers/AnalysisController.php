<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeConversation;
use App\Models\Analysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalysisController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $analyses = $request->user()
            ->analyses()
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $analyses->items(),
            'meta'    => ['pagination' => [
                'total'        => $analyses->total(),
                'current_page' => $analyses->currentPage(),
                'last_page'    => $analyses->lastPage(),
            ]],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_type'   => ['required', 'in:instagram_json,whatsapp_txt'],
            'contact_label' => ['required', 'string', 'max:255'],
            'messages'      => ['required', 'array', 'min:10'],
            'messages.*.sender'    => ['required', 'string', 'max:255'],
            'messages.*.text'      => ['required', 'string'],
            'messages.*.timestamp' => ['nullable', 'string'],
        ]);

        $user = $request->user()->load('plan');

        // Quota check
        if ($user->hasReachedAnalysisLimit()) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => ['code' => 'QUOTA_EXCEEDED', 'message' => 'Monthly analysis limit reached. Please upgrade your plan.'],
            ], 429);
        }

        // Message count limit
        $maxMessages = $user->plan->max_messages_per_analysis;
        $messages    = array_slice($data['messages'], 0, $maxMessages);

        // Idempotency
        $idempotencyKey = hash('sha256', $user->id . $data['source_type'] . $data['contact_label'] . count($messages));
        $existing       = Analysis::where('idempotency_key', $idempotencyKey)
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHours(2))
            ->first();
        if ($existing) {
            return response()->json(['success' => true, 'data' => $existing]);
        }

        $analysis = DB::transaction(function () use ($user, $data, $messages, $idempotencyKey) {
            $analysis = Analysis::create([
                'user_id'            => $user->id,
                'source_type'        => $data['source_type'],
                'contact_label_hash' => hash('sha256', $data['contact_label']),
                'idempotency_key'    => $idempotencyKey,
                'message_count'      => count($messages),
                'status'             => 'pending',
            ]);

            $user->currentUsage()->incrementAnalysis();

            return $analysis;
        });

        AnalyzeConversation::dispatch($analysis, $messages);

        return response()->json(['success' => true, 'data' => $analysis], 202);
    }

    public function show(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorizeAnalysis($request, $analysis);
        return response()->json(['success' => true, 'data' => $analysis]);
    }

    public function report(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorizeAnalysis($request, $analysis);

        if ($analysis->status !== 'completed') {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => ['code' => 'REPORT_NOT_READY', 'message' => 'Analysis not completed yet.'],
            ], 404);
        }

        $report = $analysis->report;
        if (! $report) {
            return response()->json(['success' => false, 'data' => null, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Report not found.']], 404);
        }

        return response()->json(['success' => true, 'data' => $report]);
    }

    public function destroy(Request $request, Analysis $analysis): JsonResponse
    {
        if ($analysis->user_id !== $request->user()->id) {
            abort(403);
        }
        $analysis->delete();
        return response()->json(['success' => true, 'data' => null]);
    }

    private function authorizeAnalysis(Request $request, Analysis $analysis): void
    {
        $user = $request->user();
        $isOwner  = $analysis->user_id === $user->id;
        $isShared = $analysis->sharedAccess()
            ->where('shared_with_user_id', $user->id)
            ->where('status', 'accepted')
            ->exists();

        if (! $isOwner && ! $isShared) abort(403);
    }
}
