<?php

namespace App\Services;

use App\Models\Analysis;
use App\Models\AiRequest;
use App\Models\Report;
use App\Models\User;
use App\Services\AI\AIProvider;
use App\Services\AI\GeminiProvider;
use App\Services\AI\OpenAIProvider;
use App\Services\AI\PromptBuilder;
use App\Exceptions\AIProviderException;
use Illuminate\Support\Facades\Log;

class AnalysisService
{
    private const CHUNK_SIZE = 500; // messages per chunk
    private const CHUNK_THRESHOLD = 800; // use chunking above this

    public function run(Analysis $analysis, array $messages): void
    {
        $user     = $analysis->user()->with('plan')->first();
        $provider = $this->resolveProvider($user);

        try {
            $analysis->markProcessing();

            // 1. Safety check (fast, cheap model always)
            $safetyProvider = new GeminiProvider('gemini-1.5-flash-latest');
            $safetyResult   = $this->safetyCheck($analysis, $user, $safetyProvider, $messages);

            if ($safetyResult['safety_flag']) {
                $this->saveReport($analysis, ['safety_flag' => true, 'chemistry_score' => 0], $provider);
                $analysis->update(['safety_flag' => true]);
                $analysis->markCompleted();
                return;
            }

            // 2. Main analysis (chunked or direct)
            $insights = count($messages) > self::CHUNK_THRESHOLD
                ? $this->chunkedAnalysis($analysis, $user, $provider, $messages)
                : $this->directAnalysis($analysis, $user, $provider, $messages);

            $this->saveReport($analysis, $insights, $provider);
            $analysis->markCompleted();

        } catch (\Throwable $e) {
            Log::error('Analysis failed', ['analysis_id' => $analysis->id, 'error' => $e->getMessage()]);
            $analysis->markFailed($e->getMessage());
            $this->refundQuota($user);
            throw $e;
        }
    }

    private function safetyCheck(Analysis $analysis, User $user, AIProvider $provider, array $messages): array
    {
        $prompt = PromptBuilder::safetyCheckPrompt($messages);
        return $this->callProvider($provider, $analysis, $user, PromptBuilder::systemPrompt(), $prompt, 'safety_check');
    }

    private function directAnalysis(Analysis $analysis, User $user, AIProvider $provider, array $messages): array
    {
        $prompt = PromptBuilder::fullAnalysisPrompt($messages);
        return $this->callProvider($provider, $analysis, $user, PromptBuilder::systemPrompt(), $prompt, 'full_analysis');
    }

    private function chunkedAnalysis(Analysis $analysis, User $user, AIProvider $provider, array $messages): array
    {
        $chunks       = array_chunk($messages, self::CHUNK_SIZE);
        $totalChunks  = count($chunks);
        $chunkResults = [];

        foreach ($chunks as $i => $chunk) {
            $prompt        = PromptBuilder::chunkMapPrompt($chunk, $i + 1, $totalChunks);
            $chunkResults[] = $this->callProvider($provider, $analysis, $user, PromptBuilder::systemPrompt(), $prompt, 'chunk_map');
        }

        $reducePrompt = PromptBuilder::chunkReducePrompt($chunkResults, count($messages));
        return $this->callProvider($provider, $analysis, $user, PromptBuilder::systemPrompt(), $reducePrompt, 'chunk_reduce');
    }

    private function callProvider(
        AIProvider $provider,
        Analysis $analysis,
        User $user,
        string $system,
        string $user_prompt,
        string $operation,
        int $retries = 1
    ): array {
        $attempt = 0;
        do {
            try {
                $response = $provider->complete($system, $user_prompt);
                $this->logAiRequest($analysis, $user, $provider, $operation, $response, 'success');
                $this->updateTokenUsage($user, $response['tokens_in'] + $response['tokens_out']);
                return $response['result'];
            } catch (AIProviderException $e) {
                $attempt++;
                if ($attempt > $retries) throw $e;
                sleep(2);
            }
        } while ($attempt <= $retries);
    }

    private function saveReport(Analysis $analysis, array $insights, AIProvider $provider): void
    {
        Report::updateOrCreate(
            ['analysis_id' => $analysis->id],
            [
                'chemistry_score'     => $insights['chemistry_score'] ?? 0,
                'chemistry_breakdown' => $insights['chemistry_breakdown'] ?? null,
                'common_ground'       => $insights['common_ground'] ?? null,
                'memory_box'          => $insights['memory_box'] ?? null,
                'misunderstandings'   => $insights['misunderstandings'] ?? null,
                'icebreakers'         => $insights['icebreakers'] ?? null,
                'safety_flag'         => $insights['safety_flag'] ?? false,
                'ai_model'            => $provider->getModelName(),
                'raw_ai_output'       => $insights,
                'raw_ai_expires_at'   => now()->addDays(30),
            ]
        );
    }

    private function logAiRequest(Analysis $analysis, User $user, AIProvider $provider, string $operation, array $response, string $status): void
    {
        AiRequest::create([
            'analysis_id' => $analysis->id,
            'user_id'     => $user->id,
            'provider'    => $provider->getProviderName(),
            'model'       => $response['model'],
            'operation'   => $operation,
            'tokens_in'   => $response['tokens_in'],
            'tokens_out'  => $response['tokens_out'],
            'cost_usd'    => $response['cost_usd'],
            'latency_ms'  => $response['latency_ms'],
            'status'      => $status,
        ]);
    }

    private function updateTokenUsage(User $user, int $tokens): void
    {
        $record = $user->currentUsage();
        $record->incrementTokens($tokens);
    }

    private function refundQuota(User $user): void
    {
        $record = $user->currentUsage();
        if ($record->analyses_used > 0) {
            $record->decrement('analyses_used');
        }
    }

    private function resolveProvider(User $user): AIProvider
    {
        $usePremiumModel = $user->plan->hasFeature('priority_model');
        $primary = config('services.ai.primary', 'gemini');

        if ($primary === 'openai') {
            return $usePremiumModel
                ? new OpenAIProvider('gpt-4o')
                : new OpenAIProvider('gpt-4o-mini');
        }

        return $usePremiumModel
            ? new GeminiProvider('gemini-1.5-pro-latest')
            : new GeminiProvider('gemini-1.5-flash-latest');
    }
}
