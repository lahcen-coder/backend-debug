<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AIProviderException;
use App\Exceptions\RateLimitException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GeminiAnalyzerService
 *
 * High-level service responsible for the full relationship analysis pipeline:
 *
 *   1. Safety pre-check  — fast flash model, short sample, cheap.
 *   2. Main analysis     — flagship model, full corpus, structured JSON output.
 *
 * Output contract (stored in analyses.report_data):
 * {
 *   "chemistry_score":       int (1–100),
 *   "common_interests":      string[],
 *   "communication_style":   { person_a: CommunicationProfile, person_b: CommunicationProfile },
 *   "misunderstanding_resolver": { conflicts_detected: int, resolutions: Resolution[] },
 *   "memory_box":            MemoryItem[],
 *   "activity_suggestions":  ActivitySuggestion[],
 *   "safety_flag":           bool,
 *   "generated_at":          string (ISO 8601)
 * }
 *
 * Error handling:
 *   - HTTP 429 → throws RateLimitException (job re-queues without burning a retry)
 *   - HTTP 5xx → throws AIProviderException (job retries with exponential back-off)
 *   - Timeout   → throws ConnectionException (job retries with exponential back-off)
 *   - Bad JSON  → attempts to extract JSON from prose, then throws AIProviderException
 */
class GeminiAnalyzerService
{
    private const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    /** Default model for the safety check (fast & cheap). */
    private const SAFETY_MODEL = 'gemini-1.5-flash-latest';

    /** Max messages to include in a single API request (~20k tokens at avg 10 words/msg). */
    private const MAX_MESSAGES_DIRECT = 2_000;

    /** When the corpus is larger, use map-reduce chunking. */
    private const CHUNK_SIZE = 600;

    /** Gemini 1.5 Flash pricing per 1M tokens (for cost tracking logs). */
    private const FLASH_COST_IN_PER_M  = 0.075;
    private const FLASH_COST_OUT_PER_M = 0.30;

    /** Gemini 1.5 Pro pricing per 1M tokens. */
    private const PRO_COST_IN_PER_M  = 3.50;
    private const PRO_COST_OUT_PER_M = 10.50;

    private string $apiKey;
    private string $analysisModel;
    private int    $requestTimeout;

    public function __construct(
        string $apiKey        = '',
        string $analysisModel = '',
        int    $requestTimeout = 90
    ) {
        $this->apiKey         = $apiKey        ?: (string) config('services.gemini.key');
        $this->analysisModel  = $analysisModel ?: (string) config('services.gemini.model', 'gemini-1.5-flash-latest');
        $this->requestTimeout = $requestTimeout;

        if (empty($this->apiKey)) {
            throw new \RuntimeException('GEMINI_API_KEY is not configured.');
        }
    }

    // ── Public Entry Point ────────────────────────────────────────────────────

    /**
     * Run the full analysis pipeline and return the structured report.
     *
     * @param  array  $messages  Clean message array: [{ sender, text, timestamp }]
     * @param  string $platform  'instagram' | 'whatsapp'
     * @return array             Structured report matching the output contract above.
     *
     * @throws RateLimitException     On HTTP 429.
     * @throws AIProviderException    On API errors or unparseable responses.
     * @throws ConnectionException    On network timeouts.
     */
    public function analyze(array $messages, string $platform): array
    {
        // ── Step 1: Safety pre-check (fast, always runs) ──────────────────────
        $safetyResult = $this->runSafetyCheck($messages);

        if ($safetyResult['safety_flag'] ?? false) {
            Log::info('GeminiAnalyzerService: safety flag raised', [
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

        // ── Step 2: Main analysis ─────────────────────────────────────────────
        $report = count($messages) > self::MAX_MESSAGES_DIRECT
            ? $this->runChunkedAnalysis($messages, $platform)
            : $this->runDirectAnalysis($messages, $platform);

        $report['safety_flag']   = false;
        $report['generated_at']  = now()->toIso8601String();

        return $this->validateAndNormalizeReport($report);
    }

    // ── Safety Check ──────────────────────────────────────────────────────────

    /**
     * Run a rapid safety scan on the first 60 messages using the flash model.
     *
     * @return array{ safety_flag: bool, reason: string|null }
     */
    private function runSafetyCheck(array $messages): array
    {
        $sample  = array_slice($messages, 0, 60);
        $prompt  = $this->buildSafetyCheckPrompt($sample);
        $result  = $this->callGemini(self::SAFETY_MODEL, $this->buildSafetySystemPrompt(), $prompt);

        return [
            'safety_flag' => (bool) ($result['safety_flag'] ?? false),
            'reason'      => $result['reason'] ?? null,
        ];
    }

    // ── Direct Analysis (≤ 2 000 messages) ───────────────────────────────────

    private function runDirectAnalysis(array $messages, string $platform): array
    {
        return $this->callGemini(
            $this->analysisModel,
            $this->buildSystemPrompt(),
            $this->buildAnalysisPrompt($messages, $platform)
        );
    }

    // ── Chunked (Map-Reduce) Analysis (> 2 000 messages) ─────────────────────

    private function runChunkedAnalysis(array $messages, string $platform): array
    {
        $chunks      = array_chunk($messages, self::CHUNK_SIZE);
        $totalChunks = count($chunks);
        $chunkSums   = [];

        foreach ($chunks as $index => $chunk) {
            $chunkSums[] = $this->callGemini(
                $this->analysisModel,
                $this->buildSystemPrompt(),
                $this->buildChunkMapPrompt($chunk, $index + 1, $totalChunks, $platform)
            );
        }

        return $this->callGemini(
            $this->analysisModel,
            $this->buildSystemPrompt(),
            $this->buildChunkReducePrompt($chunkSums, count($messages))
        );
    }

    // ── Gemini HTTP Client ────────────────────────────────────────────────────

    /**
     * Send a prompt to the Gemini REST API and return the decoded JSON result.
     *
     * @throws RateLimitException   On HTTP 429.
     * @throws AIProviderException  On other HTTP errors or bad JSON.
     * @throws ConnectionException  On network timeout.
     */
    private function callGemini(string $model, string $systemPrompt, string $userPrompt): array
    {
        $url  = self::API_BASE_URL . "/{$model}:generateContent";
        $body = [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
            ],
            'generationConfig' => [
                'temperature'      => 0.35,
                'topP'             => 0.9,
                'maxOutputTokens'  => 8192,
                'responseMimeType' => 'application/json',
            ],
            'safetySettings' => [
                // Relax hate-speech filter — relationship conversations can trigger false positives
                ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',  'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HARASSMENT',         'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',  'threshold' => 'BLOCK_ONLY_HIGH'],
            ],
        ];

        $startedAt = microtime(true);

        $response = Http::timeout($this->requestTimeout)
            ->withQueryParameters(['key' => $this->apiKey])
            ->post($url, $body);

        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

        // ── Handle rate limit ─────────────────────────────────────────────────
        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 60);
            throw new RateLimitException(
                "Gemini rate limit exceeded on model {$model}.",
                $retryAfter
            );
        }

        // ── Handle other HTTP errors ──────────────────────────────────────────
        if ($response->failed()) {
            $errorBody = $response->json('error.message') ?? $response->body();
            throw new AIProviderException(
                "Gemini API error (HTTP {$response->status()}) on model {$model}: {$errorBody}"
            );
        }

        // ── Handle blocked / filtered responses ───────────────────────────────
        $data          = $response->json();
        $finishReason  = $data['candidates'][0]['finishReason'] ?? 'STOP';

        if ($finishReason === 'SAFETY') {
            throw new AIProviderException(
                "Gemini blocked the response for safety reasons on model {$model}."
            );
        }

        if ($finishReason === 'RECITATION') {
            throw new AIProviderException(
                "Gemini refused the response due to recitation policy on model {$model}."
            );
        }

        // ── Extract text content ──────────────────────────────────────────────
        $rawText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($rawText)) {
            throw new AIProviderException("Gemini returned an empty response from model {$model}.");
        }

        // ── Usage / cost logging ──────────────────────────────────────────────
        $tokensIn  = $data['usageMetadata']['promptTokenCount']     ?? 0;
        $tokensOut = $data['usageMetadata']['candidatesTokenCount']  ?? 0;

        Log::debug('GeminiAnalyzerService: API call', [
            'model'      => $model,
            'tokens_in'  => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost_usd'   => $this->estimateCost($model, $tokensIn, $tokensOut),
            'latency_ms' => $latencyMs,
        ]);

        // ── Parse JSON ────────────────────────────────────────────────────────
        return $this->decodeJsonResponse($rawText, $model);
    }

    /**
     * Decode the JSON response from Gemini.
     *
     * Gemini's `responseMimeType: application/json` setting usually returns
     * clean JSON, but may occasionally wrap it in a markdown code fence.
     * We strip the fence and attempt a fallback extraction if needed.
     *
     * @throws AIProviderException If valid JSON cannot be decoded.
     */
    private function decodeJsonResponse(string $rawText, string $model): array
    {
        $cleaned = $rawText;

        // Strip markdown code fences: ```json ... ``` or ``` ... ```
        $cleaned = (string) preg_replace('/^```(?:json)?\s*/m', '', $cleaned);
        $cleaned = (string) preg_replace('/\s*```$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Fallback: try to extract the first JSON object from mixed-prose output
        if (preg_match('/\{[\s\S]+\}/m', $cleaned, $matches)) {
            $fallback = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($fallback)) {
                Log::warning('GeminiAnalyzerService: used JSON fallback extraction', [
                    'model' => $model,
                ]);
                return $fallback;
            }
        }

        throw new AIProviderException(
            "Gemini model {$model} returned unparseable JSON. " .
            'First 300 chars: ' . substr($cleaned, 0, 300)
        );
    }

    // ── Report Validation & Normalization ────────────────────────────────────

    /**
     * Ensure all required keys are present and within valid ranges.
     * Missing keys get sensible defaults so the frontend never crashes.
     */
    private function validateAndNormalizeReport(array $report): array
    {
        return [
            'chemistry_score' => max(1, min(100, (int) ($report['chemistry_score'] ?? 50))),

            'common_interests' => array_values(array_filter(
                (array) ($report['common_interests'] ?? []),
                fn ($v) => is_string($v) && strlen(trim($v)) > 0
            )),

            'communication_style' => $this->normalizeCommunicationStyle(
                $report['communication_style'] ?? []
            ),

            'misunderstanding_resolver' => $this->normalizeMisunderstandingResolver(
                $report['misunderstanding_resolver'] ?? []
            ),

            'memory_box' => $this->normalizeMemoryBox(
                $report['memory_box'] ?? []
            ),

            'activity_suggestions' => $this->normalizeActivitySuggestions(
                $report['activity_suggestions'] ?? []
            ),

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

        // Keep at most 3 items (as specified)
        return array_slice(
            array_values(array_filter(
                $raw,
                fn ($item) => is_array($item) && isset($item['moment'])
            )),
            0,
            3
        );
    }

    private function normalizeActivitySuggestions(mixed $raw): array
    {
        if (! is_array($raw)) return [];

        return array_values(array_filter(
            $raw,
            fn ($item) => is_array($item) && isset($item['activity'])
        ));
    }

    // ── Cost Estimation ───────────────────────────────────────────────────────

    private function estimateCost(string $model, int $tokensIn, int $tokensOut): float
    {
        $isPro = str_contains($model, 'pro');

        $inRate  = $isPro ? self::PRO_COST_IN_PER_M  : self::FLASH_COST_IN_PER_M;
        $outRate = $isPro ? self::PRO_COST_OUT_PER_M : self::FLASH_COST_OUT_PER_M;

        return round(
            ($tokensIn / 1_000_000) * $inRate + ($tokensOut / 1_000_000) * $outRate,
            6
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PROMPTS
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

        return <<<PROMPT
Classify this conversation sample for safety concerns. Respond with JSON only.

CONVERSATION SAMPLE ({$this->count($messages)} messages):
{$sample}
PROMPT;
    }

    private function buildSystemPrompt(): string
    {
        return <<<'SYSTEM'
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
SYSTEM;
    }

    private function buildAnalysisPrompt(array $messages, string $platform): string
    {
        $formatted    = $this->formatMessages($messages);
        $messageCount = $this->count($messages);
        $platformNote = $platform === 'instagram'
            ? 'This is an Instagram DM conversation.'
            : 'This is a WhatsApp conversation.';

        return <<<PROMPT
{$platformNote} Analyse the following {$messageCount} messages and return a JSON report
using EXACTLY this schema — no additional keys, no omitted keys:

{
  "chemistry_score": <integer 1–100. Base this on: response speed, conversation balance, positivity ratio, consistency over time. 100 = deeply in sync, 1 = rarely connecting>,

  "common_interests": [
    "<specific interest inferred from their conversation — e.g. 'late-night cooking experiments', NOT generic 'food'>",
    ... (3–8 items, all grounded in the actual chat)
  ],

  "communication_style": {
    "person_a": {
      "name": "<their display name from the conversation>",
      "style_summary": "<2–3 sentences: how they express themselves, their emotional tone, what they value in communication>",
      "strengths": ["<specific strength 1>", "<specific strength 2>"],
      "growth_edge": "<one gentle, constructive observation about a communication pattern they could grow through>",
      "initiates_conversations": <true|false>,
      "typical_response_length": "<short|medium|long>",
      "emoji_usage": "<rare|occasional|frequent>"
    },
    "person_b": {
      "name": "<their display name from the conversation>",
      "style_summary": "<2–3 sentences>",
      "strengths": ["<specific strength 1>", "<specific strength 2>"],
      "growth_edge": "<one gentle observation>",
      "initiates_conversations": <true|false>,
      "typical_response_length": "<short|medium|long>",
      "emoji_usage": "<rare|occasional|frequent>"
    }
  },

  "misunderstanding_resolver": {
    "conflicts_detected": <integer — count of distinct tension points>,
    "resolutions": [
      {
        "original_tension": "<neutral one-sentence description of what happened — no blame>",
        "likely_need_a": "<what Person A probably needed in that moment>",
        "likely_need_b": "<what Person B probably needed in that moment>",
        "reframe": "<a warm, third-party perspective that helps both people see each other's side — 2–3 sentences>"
      }
      ... (one entry per detected conflict, or empty array if none)
    ]
  },

  "memory_box": [
    {
      "type": "<funny|sweet|milestone>",
      "moment": "<a warm 1–2 sentence description of this memorable exchange>",
      "quote": "<the closest verbatim quote or paraphrase from the conversation that captures it>"
    }
    ... (exactly 3 items: pick the most memorable funny moment, the sweetest moment, and a milestone)
  ],

  "activity_suggestions": [
    {
      "activity": "<a specific, personalised suggestion — e.g. 'Try recreating that pasta dish you both kept talking about'>",
      "reason": "<1 sentence connecting this to something real in their conversation>",
      "vibe": "<cosy|adventurous|creative|relaxing|social>"
    }
    ... (3–5 suggestions, all grounded in the actual chat content)
  ]
}

CONVERSATION ({$messageCount} messages):
{$formatted}
PROMPT;
    }

    private function buildChunkMapPrompt(array $chunk, int $index, int $total, string $platform): string
    {
        $formatted = $this->formatMessages($chunk);
        $count     = $this->count($chunk);

        return <<<PROMPT
This is chunk {$index} of {$total} from a longer {$platform} conversation ({$count} messages in this chunk).
Extract a mini-summary as JSON — this will be merged later:

{
  "topics_discussed": ["<topic 1>", "<topic 2>"],
  "emotional_tone": "<positive|neutral|tense|playful|mixed>",
  "interesting_exchanges": [
    "<verbatim quote or description of a notable moment>"
  ],
  "conflicts_noted": [
    "<brief neutral description of any friction>"
  ],
  "shared_references": ["<inside joke, shared memory, or recurring theme>"]
}

CHUNK {$index}/{$total}:
{$formatted}
PROMPT;
    }

    private function buildChunkReducePrompt(array $chunkSummaries, int $totalMessages): string
    {
        $summariesJson = json_encode($chunkSummaries, JSON_PRETTY_PRINT);

        return <<<PROMPT
You have {$totalMessages} messages summarised across the following {$this->count($chunkSummaries)} chunk analyses.
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
PROMPT;
    }

    // ── Formatting Helpers ────────────────────────────────────────────────────

    /** Format message array into a readable transcript for the prompt. */
    private function formatMessages(array $messages): string
    {
        return implode("\n", array_map(
            fn (array $m): string => sprintf(
                '[%s] %s: %s',
                $m['timestamp'] ?? '',
                $m['sender']    ?? 'unknown',
                mb_strtrim($m['text'] ?? '')
            ),
            $messages
        ));
    }

    /** Type-safe count helper. */
    private function count(array $arr): int
    {
        return count($arr);
    }
}
