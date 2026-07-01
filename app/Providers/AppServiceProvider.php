<?php

namespace App\Providers;

use App\Services\AI\ConversationAnalyzer;
use App\Services\AI\GeminiAnalyzerService;
use App\Services\AI\OpenAIAnalyzerService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Resolve the active analyzer based on AI_PRIMARY (gemini | openai).
        $this->app->bind(ConversationAnalyzer::class, function () {
            $primary = (string) config('services.ai.primary', 'gemini');

            return $primary === 'openai'
                ? new OpenAIAnalyzerService()
                : new GeminiAnalyzerService();
        });
    }

    public function boot(): void
    {
        //
    }
}
