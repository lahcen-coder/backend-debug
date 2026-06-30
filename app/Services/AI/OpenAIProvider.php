<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use App\Exceptions\AIProviderException;

class OpenAIProvider implements AIProvider
{
    private string $apiKey;
    private string $model;

    private const COST_PER_1M_IN  = 0.15;  // gpt-4o-mini
    private const COST_PER_1M_OUT = 0.60;

    public function __construct(string $model = 'gpt-4o-mini')
    {
        $this->apiKey = config('services.openai.key');
        $this->model  = $model;
    }

    public function complete(string $systemPrompt, string $userPrompt, array $schema = []): array
    {
        $body = [
            'model'       => $this->model,
            'temperature' => 0.3,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        $start    = microtime(true);
        $response = Http::timeout(60)
            ->withToken($this->apiKey)
            ->post('https://api.openai.com/v1/chat/completions', $body);

        if ($response->failed()) {
            throw new AIProviderException('OpenAI API error: ' . $response->body());
        }

        $data      = $response->json();
        $text      = $data['choices'][0]['message']['content'] ?? '';
        $tokensIn  = $data['usage']['prompt_tokens'] ?? 0;
        $tokensOut = $data['usage']['completion_tokens'] ?? 0;
        $costUsd   = ($tokensIn / 1_000_000) * self::COST_PER_1M_IN
                   + ($tokensOut / 1_000_000) * self::COST_PER_1M_OUT;

        $result = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AIProviderException('OpenAI returned invalid JSON: ' . substr($text, 0, 200));
        }

        return [
            'result'     => $result,
            'tokens_in'  => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost_usd'   => $costUsd,
            'model'      => $this->model,
            'latency_ms' => (int) ((microtime(true) - $start) * 1000),
        ];
    }

    public function getProviderName(): string { return 'openai'; }
    public function getModelName(): string    { return $this->model; }
}
