<?php

namespace App\Services\AI;

interface AIProvider
{
    /**
     * Send a prompt and return parsed JSON array.
     *
     * @param  string  $systemPrompt
     * @param  string  $userPrompt
     * @param  array   $schema  JSON schema hint for structured output
     * @return array{result: array, tokens_in: int, tokens_out: int, cost_usd: float, model: string}
     */
    public function complete(string $systemPrompt, string $userPrompt, array $schema = []): array;

    public function getProviderName(): string;
    public function getModelName(): string;
}
