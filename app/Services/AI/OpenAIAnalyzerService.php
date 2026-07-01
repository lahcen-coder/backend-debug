<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AIProviderException;
use App\Exceptions\RateLimitException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAIAnalyzerService
 *
 * OpenAI-backed implementation of the relationship analysis pipeline. Mirrors
 * GeminiAnalyzerService's output contract so it is a drop-in alternative,
 * selected via AI_PRIMARY=openai.
 */
class OpenAIAnalyzerService implements ConversationAnalyzer
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    /** Send the whole corpus in one request up to this size; chunk beyond it. */
    private const MAX_MESSAGES_DIRECT = 500;

    /** Chunk size for map-reduce on long conversations. */
    private const CHUNK_SIZE = 300;

    private string $apiKey;
    private string $model;
    private int    $requestTimeout;
    private string $language = 'english';

    public function __construct(
        string $apiKey = '',
        string $model = '',
        int    $requestTimeout = 90
    ) {
        $this->apiKey         = $apiKey ?: (string) config('services.openai.key');
        $this->model          = $model  ?: (string) config('services.openai.model', 'gpt-4o-mini');
        $this->requestTimeout = $requestTimeout;

        if (empty($this->apiKey)) {
            throw new \RuntimeException('OPENAI_API_KEY is not configured.');
        }
    }

    // ── Public Entry Point ────────────────────────────────────────────────────

    public function analyze(array $messages, string $platform, string $language = 'english'): array
    {
        $this->language = $language;

        $safetyResult = $this->runSafetyCheck($messages);

        if ($safetyResult['safety_flag'] ?? false) {
            Log::info('OpenAIAnalyzerService: safety flag raised', [
                'reason' => $safetyResult['reason'] ?? 'unspecified',
            ]);

            return [
                'chemistry_score'           => 0,
                'common_interests'          => [],
                'communication_style'       => [],
                'misunderstanding_resolver' => ['conflicts_detected' => 0, 'resolutions' => []],
                'memory_box'                => [],
                'activity_suggestions'      => [],
                'safety_flag'               => true,
                'generated_at'              => now()->toIso8601String(),
            ];
        }

        $report = count($messages) > self::MAX_MESSAGES_DIRECT
            ? $this->runChunkedAnalysis($messages, $platform)
            : $this->runDirectAnalysis($messages, $platform);

        $report['safety_flag']  = false;
        $report['generated_at'] = now()->toIso8601String();

        return $this->validateAndNormalizeReport($report);
    }

    // ── Pipeline steps ────────────────────────────────────────────────────────

    private function runSafetyCheck(array $messages): array
    {
        $sample = array_slice($messages, 0, 60);
        $result = $this->callOpenAI($this->buildSafetySystemPrompt(), $this->buildSafetyCheckPrompt($sample));

        return [
            'safety_flag' => (bool) ($result['safety_flag'] ?? false),
            'reason'      => $result['reason'] ?? null,
        ];
    }

    private function runDirectAnalysis(array $messages, string $platform): array
    {
        return $this->callOpenAI(
            $this->buildSystemPrompt(),
            $this->buildAnalysisPrompt($messages, $platform)
        );
    }

    private function runChunkedAnalysis(array $messages, string $platform): array
    {
        $chunks      = array_chunk($messages, self::CHUNK_SIZE);
        $totalChunks = count($chunks);
        $chunkSums   = [];

        foreach ($chunks as $index => $chunk) {
            $chunkSums[] = $this->callOpenAI(
                $this->buildSystemPrompt(),
                $this->buildChunkMapPrompt($chunk, $index + 1, $totalChunks, $platform)
            );
        }

        return $this->callOpenAI(
            $this->buildSystemPrompt(),
            $this->buildChunkReducePrompt($chunkSums, count($messages))
        );
    }

    // ── OpenAI HTTP Client ────────────────────────────────────────────────────

    private function callOpenAI(string $systemPrompt, string $userPrompt): array
    {
        $body = [
            'model'           => $this->model,
            'temperature'     => 0.35,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
        ];

        $startedAt = microtime(true);

        $response = Http::timeout($this->requestTimeout)
            ->withToken($this->apiKey)
            ->post(self::API_URL, $body);

        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 60);
            throw new RateLimitException("OpenAI rate limit exceeded on model {$this->model}.", $retryAfter);
        }

        if ($response->failed()) {
            $errorBody = $response->json('error.message') ?? $response->body();
            throw new AIProviderException(
                "OpenAI API error (HTTP {$response->status()}) on model {$this->model}: {$errorBody}"
            );
        }

        $data    = $response->json();
        $rawText = $data['choices'][0]['message']['content'] ?? '';

        if (empty($rawText)) {
            throw new AIProviderException("OpenAI returned an empty response from model {$this->model}.");
        }

        Log::debug('OpenAIAnalyzerService: API call', [
            'model'      => $this->model,
            'tokens_in'  => $data['usage']['prompt_tokens']     ?? 0,
            'tokens_out' => $data['usage']['completion_tokens'] ?? 0,
            'latency_ms' => $latencyMs,
        ]);

        return $this->decodeJsonResponse($rawText);
    }

    private function decodeJsonResponse(string $rawText): array
    {
        $cleaned = trim($rawText);
        $cleaned = (string) preg_replace('/^```(?:json)?\s*/m', '', $cleaned);
        $cleaned = (string) preg_replace('/\s*```$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]+\}/m', $cleaned, $matches)) {
            $fallback = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($fallback)) {
                return $fallback;
            }
        }

        throw new AIProviderException(
            "OpenAI model {$this->model} returned unparseable JSON. First 300 chars: " . substr($cleaned, 0, 300)
        );
    }

    // ── Report Validation & Normalization ────────────────────────────────────

    private function validateAndNormalizeReport(array $report): array
    {
        return [
            'chemistry_score' => max(1, min(100, (int) ($report['chemistry_score'] ?? 50))),

            'common_interests' => array_values(array_filter(
                (array) ($report['common_interests'] ?? []),
                fn ($v) => is_string($v) && strlen(trim($v)) > 0
            )),

            'communication_style' => $this->normalizeCommunicationStyle($report['communication_style'] ?? []),

            'misunderstanding_resolver' => $this->normalizeMisunderstandingResolver($report['misunderstanding_resolver'] ?? []),

            'memory_box' => $this->normalizeMemoryBox($report['memory_box'] ?? []),

            'activity_suggestions' => $this->normalizeActivitySuggestions($report['activity_suggestions'] ?? []),

            'safety_flag'  => (bool)   ($report['safety_flag']  ?? false),
            'generated_at' => (string) ($report['generated_at'] ?? now()->toIso8601String()),
        ];
    }

    private function normalizeCommunicationStyle(mixed $raw): array
    {
        if (! is_array($raw)) return ['person_a' => [], 'person_b' => []];

        return [
            'person_a' => is_array($raw['person_a'] ?? null) ? $raw['person_a'] : [],
            'person_b' => is_array($raw['person_b'] ?? null) ? $raw['person_b'] : [],
        ];
    }

    private function normalizeMisunderstandingResolver(mixed $raw): array
    {
        if (! is_array($raw)) {
            return ['conflicts_detected' => 0, 'resolutions' => []];
        }

        return [
            'conflicts_detected' => (int) ($raw['conflicts_detected'] ?? 0),
            'resolutions'        => array_values(array_filter(
                (array) ($raw['resolutions'] ?? []),
                fn ($r) => is_array($r) && isset($r['reframe'])
            )),
        ];
    }

    private function normalizeMemoryBox(mixed $raw): array
    {
        if (! is_array($raw)) return [];

        return array_slice(
            array_values(array_filter($raw, fn ($item) => is_array($item) && isset($item['moment']))),
            0,
            3
        );
    }

    private function normalizeActivitySuggestions(mixed $raw): array
    {
        if (! is_array($raw)) return [];

        return array_values(array_filter($raw, fn ($item) => is_array($item) && isset($item['activity'])));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PROMPTS (kept identical to the Gemini pipeline for output parity)
    // ══════════════════════════════════════════════════════════════════════════

    private function buildSafetySystemPrompt(): string
    {
        return <<<'SYSTEM'
You are a content safety classifier. Your only task is to identify whether a conversation contains:
- Explicit threats of violence or self-harm
- Non-consensual sexual content or descriptions of abuse
- Coercive or controlling behaviour patterns
- Descriptions of ongoing criminal activity targeting another person

You are NOT a relationship judge. Normal arguments, strong language, or adult topics are NOT safety flags.
Respond ONLY with valid JSON: {"safety_flag": true|false, "reason": "one sentence if flagged, else null"}
SYSTEM;
    }

    private function buildSafetyCheckPrompt(array $messages): string
    {
        $sample = $this->formatMessages($messages);
        $count  = count($messages);

        return <<<PROMPT
Classify this conversation sample for safety concerns. Respond with JSON only.

CONVERSATION SAMPLE ({$count} messages):
{$sample}
PROMPT;
    }

    private function buildSystemPrompt(): string
    {
        $languageDirective = $this->languageDirective();

        return <<<SYSTEM
You are "Debug Together" — a warm, emotionally intelligent relationship enhancer.
You are NOT a therapist, judge, or referee. You are like a kind mutual friend who
sees the best in both people and helps them understand each other better.

ABSOLUTE RULES — never violate any of these:
1. NEVER assign blame, declare winners, or shame either person.
2. ALWAYS frame observations with compassion and curiosity, never criticism.
3. When describing friction or conflict, focus on BOTH parties' unmet needs.
4. Tone: warm, encouraging, gently playful — like a trusted friend, not a consultant.
5. Output ONLY strict valid JSON matching the requested schema. Zero prose outside JSON.
6. If safety_flag was raised in a prior step, set chemistry_score to 0 and return minimal data.
7. Base every insight on EVIDENCE from the actual conversation — no generic platitudes.
8. {$languageDirective}
SYSTEM;
    }

    /**
     * Instruction telling the model which language to write all human-readable
     * report text in. JSON keys/enums stay in English; only the values change.
     */
    private function languageDirective(): string
    {
        return match ($this->language) {
            'spanish' => 'LANGUAGE: Write every human-readable string VALUE (summaries, interests, '
                . 'suggestions, quotes descriptions, etc.) in natural Spanish (Español). '
                . 'Keep all JSON keys and fixed enum values (funny/sweet/milestone, vibe values, '
                . 'short/medium/long, rare/occasional/frequent) exactly in English.',
            'darija'  => 'LANGUAGE: Write every human-readable string VALUE (summaries, interests, '
                . 'suggestions, quotes descriptions, etc.) in Moroccan Darija (الدارجة المغربية) using '
                . 'Arabic script — natural, warm everyday Moroccan Arabic, not Modern Standard Arabic. '
                . 'Keep all JSON keys and fixed enum values (funny/sweet/milestone, vibe values, '
                . 'short/medium/long, rare/occasional/frequent) exactly in English.',
            default   => 'LANGUAGE: Write every human-readable string value in clear, natural English.',
        };
    }

    private function buildAnalysisPrompt(array $messages, string $platform): string
    {
        $formatted    = $this->formatMessages($messages);
        $messageCount = count($messages);
        $platformNote = $platform === 'instagram'
            ? 'This is an Instagram DM conversation.'
            : 'This is a WhatsApp conversation.';

        return <<<PROMPT
{$platformNote} Analyse the following {$messageCount} messages and return a JSON report
using EXACTLY this schema — no additional keys, no omitted keys:

{
  "chemistry_score": <integer 1-100. Base this on: response speed, conversation balance, positivity ratio, consistency over time. 100 = deeply in sync, 1 = rarely connecting>,

  "common_interests": [
    "<specific interest inferred from their conversation>",
    ... (3-8 items, all grounded in the actual chat)
  ],

  "communication_style": {
    "person_a": {
      "name": "<their display name from the conversation>",
      "style_summary": "<2-3 sentences: how they express themselves, their emotional tone, what they value in communication>",
      "strengths": ["<specific strength 1>", "<specific strength 2>"],
      "growth_edge": "<one gentle, constructive observation>",
      "initiates_conversations": <true|false>,
      "typical_response_length": "<short|medium|long>",
      "emoji_usage": "<rare|occasional|frequent>"
    },
    "person_b": {
      "name": "<their display name from the conversation>",
      "style_summary": "<2-3 sentences>",
      "strengths": ["<specific strength 1>", "<specific strength 2>"],
      "growth_edge": "<one gentle observation>",
      "initiates_conversations": <true|false>,
      "typical_response_length": "<short|medium|long>",
      "emoji_usage": "<rare|occasional|frequent>"
    }
  },

  "misunderstanding_resolver": {
    "conflicts_detected": <integer>,
    "resolutions": [
      {
        "original_tension": "<neutral one-sentence description — no blame>",
        "likely_need_a": "<what Person A probably needed>",
        "likely_need_b": "<what Person B probably needed>",
        "reframe": "<a warm, third-party perspective — 2-3 sentences>"
      }
      ... (one entry per detected conflict, or empty array if none)
    ]
  },

  "memory_box": [
    {
      "type": "<funny|sweet|milestone>",
      "moment": "<a warm 1-2 sentence description of this memorable exchange>",
      "quote": "<the closest verbatim quote or paraphrase>"
    }
    ... (exactly 3 items: funniest, sweetest, and a milestone)
  ],

  "activity_suggestions": [
    {
      "activity": "<a specific, personalised suggestion>",
      "reason": "<1 sentence connecting this to something real in their conversation>",
      "vibe": "<cosy|adventurous|creative|relaxing|social>"
    }
    ... (3-5 suggestions, all grounded in the actual chat content)
  ]
}

CONVERSATION ({$messageCount} messages):
{$formatted}

{$this->languageReminder()}
PROMPT;
    }

    private function buildChunkMapPrompt(array $chunk, int $index, int $total, string $platform): string
    {
        $formatted = $this->formatMessages($chunk);
        $count     = count($chunk);

        return <<<PROMPT
This is chunk {$index} of {$total} from a longer {$platform} conversation ({$count} messages in this chunk).
Extract a mini-summary as JSON — this will be merged later:

{
  "topics_discussed": ["<topic 1>", "<topic 2>"],
  "emotional_tone": "<positive|neutral|tense|playful|mixed>",
  "interesting_exchanges": ["<verbatim quote or description of a notable moment>"],
  "conflicts_noted": ["<brief neutral description of any friction>"],
  "shared_references": ["<inside joke, shared memory, or recurring theme>"]
}

CHUNK {$index}/{$total}:
{$formatted}
PROMPT;
    }

    private function buildChunkReducePrompt(array $chunkSummaries, int $totalMessages): string
    {
        $summariesJson = json_encode($chunkSummaries, JSON_PRETTY_PRINT);
        $chunkCount    = count($chunkSummaries);

        return <<<PROMPT
You have {$totalMessages} messages summarised across the following {$chunkCount} chunk analyses.
Synthesise them into a FINAL complete report using EXACTLY the same full JSON schema as the single-pass analysis:

{
  "chemistry_score": ...,
  "common_interests": [...],
  "communication_style": { "person_a": {...}, "person_b": {...} },
  "misunderstanding_resolver": { "conflicts_detected": ..., "resolutions": [...] },
  "memory_box": [...],
  "activity_suggestions": [...]
}

Use the chunk data as evidence. Do not repeat information — synthesise it.

CHUNK SUMMARIES:
{$summariesJson}

{$this->languageReminder()}
PROMPT;
    }

    /**
     * Short, forceful reminder placed at the END of the analysis prompt.
     * Models weight end-of-prompt instructions heavily, which fixes cases where
     * short fields (like common_interests) leak back to English.
     */
    private function languageReminder(): string
    {
        return match ($this->language) {
            'spanish' => 'CRITICAL: EVERY string value in the JSON — including common_interests, '
                . 'strengths, topics, activities and ALL short phrases — MUST be written in Spanish (Español). '
                . 'Do NOT leave any value in English except the fixed enum values and JSON keys.',
            'darija'  => 'CRITICAL: EVERY string value in the JSON — including common_interests, '
                . 'strengths, topics, activities and ALL short phrases — MUST be written in Moroccan Darija '
                . '(الدارجة المغربية) in Arabic script. Do NOT leave any value in English or Modern Standard '
                . 'Arabic, except the fixed enum values and JSON keys.',
            default   => 'Write every string value in clear, natural English.',
        };
    }

    private function formatMessages(array $messages): string
    {
        return implode("\n", array_map(
            fn (array $m): string => sprintf(
                '[%s] %s: %s',
                $m['timestamp'] ?? '',
                $m['sender']    ?? 'unknown',
                trim((string) ($m['text'] ?? ''))
            ),
            $messages
        ));
    }
}
