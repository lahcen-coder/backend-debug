<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AIProviderException;
use Illuminate\Support\Facades\Log;

/**
 * FallbackAnalyzerService
 *
 * Resilient ConversationAnalyzer that tries the configured PRIMARY provider
 * first and automatically falls back to the alternate provider if the primary
 * is unconfigured (missing API key) or fails at runtime.
 *
 * This implements the "OPENAI_API_KEY = optional fallback provider" behaviour
 * described in .env.example, which was previously never wired up: a single
 * missing key (e.g. GEMINI_API_KEY) used to hard-fail every analysis and
 * surface to the user as "Request failed with status code 500".
 */
class FallbackAnalyzerService implements ConversationAnalyzer
{
    /** Provider name ('gemini'|'openai') that produced the last successful run. */
    private string $usedProvider = '';

    public function __construct(private readonly string $primary = 'gemini') {}

    public function analyze(array $messages, string $platform, string $language = 'english'): array
    {
        $lastError = null;

        foreach ($this->providerOrder() as $name) {
            $analyzer = $this->makeAnalyzer($name);

            if ($analyzer === null) {
                // Provider not configured (missing key) — skip to the next one.
                continue;
            }

            try {
                $report = $analyzer->analyze($messages, $platform, $language);
                $this->usedProvider = $name;

                return $report;
            } catch (\Throwable $e) {
                Log::warning('FallbackAnalyzer: provider failed, trying next if available', [
                    'provider' => $name,
                    'error'    => $e->getMessage(),
                    'class'    => $e::class,
                ]);
                $lastError = $e;
            }
        }

        // Nothing worked. Re-throw the last real error (so rate-limit handling
        // and retries in the job still apply), or a clear config error if no
        // provider was even available.
        throw $lastError ?? new AIProviderException(
            'No AI provider is configured. Set OPENAI_API_KEY and/or GEMINI_API_KEY.'
        );
    }

    /**
     * Name of the provider that produced the last successful analysis, so the
     * job can record an accurate ai_provider value.
     */
    public function usedProvider(): string
    {
        return $this->usedProvider;
    }

    /**
     * Provider attempt order: the configured primary first, then the alternate.
     *
     * @return string[]
     */
    private function providerOrder(): array
    {
        return $this->primary === 'openai'
            ? ['openai', 'gemini']
            : ['gemini', 'openai'];
    }

    /**
     * Construct a provider, returning null if it cannot be built (e.g. because
     * its API key is not configured — the constructors throw in that case).
     */
    private function makeAnalyzer(string $name): ?ConversationAnalyzer
    {
        try {
            return $name === 'openai'
                ? new OpenAIAnalyzerService()
                : new GeminiAnalyzerService();
        } catch (\Throwable $e) {
            Log::info('FallbackAnalyzer: provider unavailable', [
                'provider' => $name,
                'reason'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
