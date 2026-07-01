<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\RateLimitException;
use App\Models\Analysis;
use App\Services\AI\ConversationAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * AnalyzeConversation
 *
 * Asynchronously runs the AI analysis pipeline for a submitted conversation.
 * Dispatched by AnalysisController::store() and processed by Laravel Horizon
 * on the dedicated "analyses" queue.
 *
 * Lifecycle:
 *   pending → processing → completed | failed
 *
 * Retry strategy:
 *   - Max 3 attempts with EXPONENTIAL back-off (60 s → 180 s → 540 s).
 *   - Rate-limit responses (HTTP 429) re-queue the job with the provider's
 *     Retry-After delay rather than consuming a retry attempt.
 *   - All other exceptions propagate and trigger the back-off schedule.
 *   - The failed() hook fires after all attempts are exhausted and writes
 *     a permanent `failed` status so the frontend can notify the user.
 */
class AnalyzeConversation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum wall-clock seconds this job may run before being killed.
     * Gemini 1.5 Pro can take ~90 s for large prompts; 300 s is safe.
     */
    public int $timeout = 300;

    /**
     * Maximum number of queue attempts before the job is permanently failed.
     */
    public int $tries = 3;

    /**
     * Exponential back-off: attempt 1 → 60 s, attempt 2 → 180 s, attempt 3 → 540 s.
     * This allows transient API outages to resolve before retrying.
     *
     * @return int[]
     */
    public function backoff(): array
    {
        return [60, 180, 540];
    }

    /**
     * @param Analysis $analysis The analysis record (Eloquent model serialized by ID).
     * @param array    $messages Clean message array from the client parser.
     *                           Format: [{ sender: string, text: string, timestamp: string }]
     *                           NOTE: This data lives ONLY in the queue payload and is
     *                           discarded after the job runs. It is never persisted to DB.
     */
    public function __construct(
        private readonly Analysis $analysis,
        private readonly array $messages,
        private readonly string $language = 'english'
    ) {}

    /**
     * Prevent duplicate concurrent runs for the same analysis.
     * The lock expires automatically after $timeout seconds.
     *
     * @return array<\Illuminate\Queue\Middleware\WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("analysis:{$this->analysis->id}"))
                ->expireAfter($this->timeout)
                ->releaseAfter(30),
        ];
    }

    /**
     * Execute the job.
     *
     * Marked as `processing`, the AI service is called, and the result is
     * stored as `report_data` JSON on the Analysis record via markCompleted().
     */
    public function handle(ConversationAnalyzer $analyzer): void
    {
        // Guard: if already completed or failed from a previous attempt, bail out.
        $this->analysis->refresh();
        if ($this->analysis->status === 'completed') {
            Log::info('AnalyzeConversation skipped — already completed', [
                'analysis_id' => $this->analysis->id,
            ]);
            return;
        }

        Log::info('AnalyzeConversation starting', [
            'analysis_id'   => $this->analysis->id,
            'platform'      => $this->analysis->platform,
            'message_count' => count($this->messages),
            'attempt'       => $this->attempts(),
        ]);

        $this->analysis->markProcessing();

        try {
            $provider = (string) config('services.ai.primary', 'gemini');
            $report   = $analyzer->analyze($this->messages, $this->analysis->platform, $this->language);

            $this->analysis->markCompleted($report, $provider);

            Log::info('AnalyzeConversation completed', [
                'analysis_id'     => $this->analysis->id,
                'chemistry_score' => $report['chemistry_score'] ?? null,
            ]);
        } catch (RateLimitException $e) {
            // Do NOT count this as a failed attempt — release back to queue
            // with the provider's suggested delay so we respect the rate limit.
            $retryAfter = $e->getRetryAfter();

            Log::warning('AnalyzeConversation rate-limited — requeueing', [
                'analysis_id' => $this->analysis->id,
                'retry_after' => $retryAfter,
            ]);

            // Reset status so the next attempt re-sets it to `processing`
            $this->analysis->update(['status' => 'pending']);

            $this->release($retryAfter);
        } catch (\Throwable $e) {
            Log::error('AnalyzeConversation attempt failed', [
                'analysis_id' => $this->analysis->id,
                'attempt'     => $this->attempts(),
                'max_tries'   => $this->tries,
                'error'       => $e->getMessage(),
                'class'       => $e::class,
            ]);

            // Mark as failed only on the final attempt; otherwise re-throw so
            // the queue manager applies the backoff and retries.
            if ($this->attempts() >= $this->tries) {
                $this->analysis->markFailed(
                    "Exhausted {$this->tries} attempts. Last error: " . $e->getMessage()
                );
                return; // Do not re-throw — failed() hook will handle cleanup.
            }

            throw $e;
        }
    }

    /**
     * Called by the queue manager after ALL retry attempts have been exhausted.
     *
     * This runs AFTER handle() has thrown on the final attempt. At this point
     * the retry budget is gone and the job will not run again.
     */
    public function failed(\Throwable $e): void
    {
        Log::critical('AnalyzeConversation permanently failed', [
            'analysis_id' => $this->analysis->id,
            'error'       => $e->getMessage(),
        ]);

        // Ensure the record reflects the terminal failure state.
        if ($this->analysis->status !== 'failed') {
            $this->analysis->markFailed('Job permanently failed: ' . $e->getMessage());
        }

        // Refund the usage quota so the user can re-submit.
        $usage = $this->analysis->user()->first()?->currentUsage();
        if ($usage && $usage->analyses_used > 0) {
            $usage->decrement('analyses_used');
        }
    }
}
