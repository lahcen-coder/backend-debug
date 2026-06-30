<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use App\Exceptions\AIProviderException;

class GeminiProvider implements AIProvider
{
    private string $apiKey;
    private string $model;

    // Gemini 1.5 Flash pricing (input/output per 1M tokens)
    private const COST_PER_1M_IN  = 0.075;
    private const COST_PER_1M_OUT = 0.30;

    public function __construct(string $model = 'gemini-1.5-flash-latest')
    {
        $this->apiKey = config('services.gemini.key');
        $this->model  = $model;
    }

    public function complete(string $systemPrompt, string $userPrompt, array $schema = []): array
    {
        $body = [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'           => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'generationConfig'   => [
                'temperature'     => 0.3,
                'maxOutputTokens' => 4096,
                'responseMimeType' => 'application/json',
            ],
        ];

        $start    = microtime(true);
        $response = Http::timeout(60)
            ->withQueryParameters(['key' => $this->apiKey])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent", $body);

        if ($response->failed()) {
            throw new AIProviderException('Gemini API error: ' . $response->body());
        }

        $data      = $response->json();
        $text      = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $tokensIn  = $data['usageMetadata']['promptTokenCount'] ?? 0;
        $tokensOut = $data['usageMetadata']['candidatesTokenCount'] ?? 0;
        $costUsd   = ($tokensIn / 1_000_000) * self::COST_PER_1M_IN
                   + ($tokensOut / 1_000_000) * self::COST_PER_1M_OUT;

        $result = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AIProviderException('Gemini returned invalid JSON: ' . substr($text, 0, 200));
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

    public function getProviderName(): string { return 'gemini'; }
    public function getModelName(): string    { return $this->model; }
}
