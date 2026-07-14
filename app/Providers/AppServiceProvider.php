<?php

namespace App\Providers;

use App\Services\AI\ConversationAnalyzer;
use App\Services\AI\FallbackAnalyzerService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Resolve the analyzer with automatic provider fallback: the configured
        // AI_PRIMARY (gemini | openai) is tried first, and the alternate provider
        // is used if the primary is unconfigured or fails. This prevents a single
        // missing API key from hard-failing every analysis.
        $this->app->bind(ConversationAnalyzer::class, function () {
            $primary = (string) config('services.ai.primary', 'gemini');

            return new FallbackAnalyzerService($primary);
        });
    }

    public function boot(): void
    {
        //
    }
}
