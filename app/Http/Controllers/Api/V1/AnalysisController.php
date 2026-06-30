<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeConversation;
use App\Models\Analysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AnalysisController extends Controller
{
    /**
     * List the authenticated user's analyses (paginated).
     *
     * GET /api/v1/analyses
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'   => ['nullable', Rule::in(['pending', 'processing', 'completed', 'failed'])],
            'platform' => ['nullable', Rule::in(['instagram', 'whatsapp'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $analyses = $request->user()
            ->analyses()
            ->when($request->status,   fn ($q) => $q->where('status', $request->status))
            ->when($request->platform, fn ($q) => $q->where('platform', $request->platform))
            ->select([
                'id', 'platform', 'status', 'message_count',
                'ai_provider', 'safety_flag', 'processed_at', 'created_at',
            ])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => $analyses->items(),
            'meta'    => [
                'pagination' => [
                    'total'        => $analyses->total(),
                    'per_page'     => $analyses->perPage(),
                    'current_page' => $analyses->currentPage(),
                    'last_page'    => $analyses->lastPage(),
                    'from'         => $analyses->firstItem(),
                    'to'           => $analyses->lastItem(),
                ],
            ],
        ]);
    }

    /**
     * Submit a new chat analysis.
     *
     * Validates the incoming clean message array (parsed client-side for privacy),
     * enforces plan quotas, deduplicates via payload hash, creates an Analysis
     * record in `pending` status, dispatches the background job, and returns
     * the Analysis ID immediately (202 Accepted).
     *
     * POST /api/v1/analyses
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user()->load('plan');

        // ── Quota enforcement (also enforced by CheckQuota middleware on the route) ──
        if ($user->hasReachedAnalysisLimit()) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => [
                    'code'    => 'QUOTA_EXCEEDED',
                    'message' => 'You have used all your analyses for this month. Upgrade your plan for more.',
                ],
            ], 429);
        }

        // ── Input validation ──────────────────────────────────────────────────
        $data = $request->validate([
            'platform'     => ['required', Rule::in(['instagram', 'whatsapp'])],
            'partner_name' => ['required', 'string', 'max:255'],
            'messages'     => ['required', 'array', 'min:10'],
            // Per-message rules — validated lazily to give a clear field-level error
            'messages.*.sender'    => ['required', 'string', 'max:255'],
            'messages.*.text'      => ['required', 'string', 'max:10000'],
            'messages.*.timestamp' => ['nullable', 'string', 'max:50'],
        ]);

        // ── Enforce plan message limit ────────────────────────────────────────
        $maxMessages = $user->plan->max_messages_per_analysis;
        $messages    = array_slice($data['messages'], 0, $maxMessages);

        // ── Deduplication via content hash ────────────────────────────────────
        // Build a stable, sorted hash of the cleaned message corpus so the same
        // conversation cannot be submitted twice within the cooldown window.
        $payloadHash = $this->computePayloadHash($messages);

        $existing = Analysis::where('user_id', $user->id)
            ->where('clean_payload_hash', $payloadHash)
            ->where('created_at', '>=', now()->subHours(24))
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'data'    => $this->formatAnalysis($existing),
                'message' => 'Duplicate submission — returning existing analysis.',
            ], 200);
        }

        // ── Persist + dispatch ────────────────────────────────────────────────
        $analysis = DB::transaction(function () use ($user, $data, $messages, $payloadHash) {
            $analysis = Analysis::create([
                'user_id'            => $user->id,
                'partner_name'       => Analysis::hashPartnerName($data['partner_name']),
                'platform'           => $data['platform'],
                'status'             => 'pending',
                'clean_payload_hash' => $payloadHash,
                'message_count'      => count($messages),
            ]);

            // Increment usage counter atomically inside the transaction
            $user->currentUsage()->increment('analyses_used');

            return $analysis;
        });

        // Dispatch to Redis/Horizon queue — messages are NOT stored in the DB.
        // They live in the queue payload only, and are discarded after processing.
        AnalyzeConversation::dispatch($analysis, $messages)
            ->onQueue('analyses');

        return response()->json([
            'success' => true,
            'data'    => $this->formatAnalysis($analysis),
            'message' => 'Analysis queued. Poll GET /api/v1/analyses/{id} for status updates.',
        ], 202);
    }

    /**
     * Get the status and result of a specific analysis.
     *
     * Returns `report_data` only when status is `completed`.
     *
     * GET /api/v1/analyses/{analysis}
     */
    public function show(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorizeAccess($request, $analysis);

        $payload = $this->formatAnalysis($analysis);

        // Include full report_data only when completed
        if ($analysis->isCompleted()) {
            $payload['report_data'] = $analysis->report_data;
        }

        return response()->json([
            'success' => true,
            'data'    => $payload,
        ]);
    }

    /**
     * Fetch the detailed report for a completed analysis.
     *
     * GET /api/v1/analyses/{analysis}/report
     */
    public function report(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorizeAccess($request, $analysis);

        if (! $analysis->isCompleted()) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => [
                    'code'    => 'REPORT_NOT_READY',
                    'message' => 'The analysis has not finished yet. Current status: ' . $analysis->status,
                ],
            ], 409);
        }

        if ($analysis->safety_flag) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => [
                    'code'    => 'SAFETY_FLAG',
                    'message' => 'This conversation was flagged during safety review and cannot be analysed.',
                ],
            ], 422);
        }

        if (empty($analysis->report_data)) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => ['code' => 'REPORT_EMPTY', 'message' => 'Report data is missing.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'analysis'    => $this->formatAnalysis($analysis),
                'report'      => $analysis->report_data,
                'generated_at' => $analysis->processed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Delete an analysis (GDPR right to erasure on user data).
     *
     * DELETE /api/v1/analyses/{analysis}
     */
    public function destroy(Request $request, Analysis $analysis): JsonResponse
    {
        if ($analysis->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'You do not own this analysis.'],
            ], 403);
        }

        $analysis->delete();

        return response()->json(['success' => true, 'data' => null], 200);
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * Compute a stable SHA-256 hash of the message corpus for deduplication.
     *
     * Messages are sorted by timestamp before hashing so that order differences
     * in the same corpus produce the same hash.
     */
    private function computePayloadHash(array $messages): string
    {
        usort($messages, fn ($a, $b) => strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? '')));

        $normalized = array_map(
            fn ($m) => mb_strtolower(trim($m['sender'])) . '|' . mb_strtolower(trim($m['text'])),
            $messages
        );

        return hash('sha256', implode("\n", $normalized));
    }

    /**
     * Build the public-facing analysis payload (without report_data by default).
     */
    private function formatAnalysis(Analysis $analysis): array
    {
        return [
            'id'            => $analysis->id,
            'platform'      => $analysis->platform,
            'status'        => $analysis->status,
            'message_count' => $analysis->message_count,
            'ai_provider'   => $analysis->ai_provider,
            'safety_flag'   => $analysis->safety_flag,
            'processed_at'  => $analysis->processed_at?->toIso8601String(),
            'created_at'    => $analysis->created_at->toIso8601String(),
        ];
    }

    /**
     * Authorize access to an analysis: the requester must be the owner
     * OR hold an accepted shared-access grant.
     */
    private function authorizeAccess(Request $request, Analysis $analysis): void
    {
        $user     = $request->user();
        $isOwner  = $analysis->user_id === $user->id;
        $isShared = $analysis->sharedAccess()
            ->where('shared_with_user_id', $user->id)
            ->where('status', 'accepted')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if (! $isOwner && ! $isShared) {
            abort(403, 'You do not have access to this analysis.');
        }
    }
}
