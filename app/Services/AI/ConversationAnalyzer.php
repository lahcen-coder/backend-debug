<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Common contract for a full relationship-analysis pipeline, regardless of the
 * underlying AI provider (Gemini, OpenAI, …). The queue job depends on this
 * interface so the provider can be swapped via the AI_PRIMARY config.
 */
interface ConversationAnalyzer
{
    /**
     * Run the full analysis pipeline and return the structured report.
     *
     * @param  array  $messages  Clean message array: [{ sender, text, timestamp }]
     * @param  string $platform  'instagram' | 'whatsapp'
     * @return array             Structured report (chemistry_score, …).
     */
    public function analyze(array $messages, string $platform): array;
}
