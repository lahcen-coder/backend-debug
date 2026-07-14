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
    private const MAX_MESSAGES_DIRECT = 1500;

    /** Chunk size for map-reduce on long conversations. */
    private const CHUNK_SIZE = 500;

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
                'top_words'                 => [],
                'most_positive'             => [],
                'conversation_summary'      => '',
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

    /**
     * The full report schema is large (2 communication profiles, 5 connection
     * questions, 4 sweet messages, love languages, memory box, etc.). Asking
     * for it in a single completion risks hitting the model's token ceiling —
     * especially for non-Latin scripts like Darija, which need far more
     * tokens per unit of content than English/Spanish. Splitting the schema
     * into two smaller calls (core + extended) roughly halves the per-call
     * output size, giving enough headroom regardless of language.
     */
    private function runDirectAnalysis(array $messages, string $platform): array
    {
        $core = $this->callOpenAI(
            $this->buildSystemPrompt(),
            $this->buildCoreAnalysisPrompt($messages, $platform)
        );

        $extended = $this->callOpenAI(
            $this->buildSystemPrompt(),
            $this->buildExtendedAnalysisPrompt($messages, $platform)
        );

        return array_merge($core, $extended);
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

        $core = $this->callOpenAI(
            $this->buildSystemPrompt(),
            $this->buildCoreReducePrompt($chunkSums, count($messages))
        );

        $extended = $this->callOpenAI(
            $this->buildSystemPrompt(),
            $this->buildExtendedReducePrompt($chunkSums, count($messages))
        );

        return array_merge($core, $extended);
    }

    // ── OpenAI HTTP Client ────────────────────────────────────────────────────

    private function callOpenAI(string $systemPrompt, string $userPrompt): array
    {
        $body = [
            'model'           => $this->model,
            'temperature'     => 0.5,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
        ];

        // Only send max_tokens if explicitly configured. Otherwise let the model
        // use its own native ceiling — hardcoding a value above what the deployed
        // model supports makes OpenAI reject EVERY request with HTTP 400, which
        // silently surfaced to the user as "Request failed with status code 500".
        $maxTokens = config('services.openai.max_tokens');
        if (! empty($maxTokens)) {
            $body['max_tokens'] = (int) $maxTokens;
        }

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

        // ── Handle truncated output ────────────────────────────────────────────
        // Fail loudly and distinctly instead of silently trying to parse
        // cut-off JSON. The queue job will retry with back-off.
        $finishReason = $data['choices'][0]['finish_reason'] ?? 'stop';
        if ($finishReason === 'length') {
            throw new AIProviderException(
                "OpenAI response was truncated (finish_reason=length) on model {$this->model}. " .
                'The requested output exceeded the token budget.'
            );
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

            'connection_questions' => $this->normalizeConnectionQuestions($report['connection_questions'] ?? []),

            'love_languages'  => $this->normalizeLoveLanguages($report['love_languages'] ?? []),
            'sweet_messages'  => $this->normalizeSweetMessages($report['sweet_messages'] ?? []),
            'make_them_happy' => $this->normalizeMakeThemHappy($report['make_them_happy'] ?? []),

            'top_words'            => $this->normalizeTopWords($report['top_words'] ?? []),
            'most_positive'        => $this->normalizeMostPositive($report['most_positive'] ?? []),
            'conversation_summary' => trim((string) ($report['conversation_summary'] ?? '')),

            'safety_flag'  => (bool)   ($report['safety_flag']  ?? false),
            'generated_at' => (string) ($report['generated_at'] ?? now()->toIso8601String()),
        ];
    }

    private function normalizeTopWords(mixed $raw): array
    {
        if (! is_array($raw)) return [];

        return array_slice(
            array_values(array_filter(array_map(
                function ($item) {
                    if (! is_array($item) || empty($item['word'])) return null;
                    return [
                        'word'  => (string) $item['word'],
                        'count' => max(1, (int) ($item['count'] ?? 1)),
                    ];
                },
                $raw
            ))),
            0,
            12
        );
    }

    private function normalizeMostPositive(mixed $raw): array
    {
        if (! is_array($raw)) return [];

        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') return [];

        return [
            'name'   => $name,
            'reason' => trim((string) ($raw['reason'] ?? '')),
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

    private function normalizeConnectionQuestions(mixed $raw): array
    {
        if (! is_array($raw)) return [];

        return array_slice(
            array_values(array_filter(
                $raw,
                fn ($item) => is_array($item)
                    && isset($item['question'])
                    && is_string($item['question'])
                    && strlen(trim($item['question'])) > 0
            )),
            0,
            6
        );
    }

    private function normalizeLoveLanguages(mixed $raw): array
    {
        if (! is_array($raw)) return ['person_a' => [], 'person_b' => []];

        $person = function (mixed $p): array {
            if (! is_array($p)) return [];
            return [
                'name'             => (string) ($p['name'] ?? ''),
                'primary'          => (string) ($p['primary'] ?? ''),
                'how_to_show_love' => array_values(array_filter(
                    (array) ($p['how_to_show_love'] ?? []),
                    fn ($v) => is_string($v) && strlen(trim($v)) > 0
                )),
            ];
        };

        return [
            'person_a' => $person($raw['person_a'] ?? null),
            'person_b' => $person($raw['person_b'] ?? null),
        ];
    }

    private function normalizeSweetMessages(mixed $raw): array
    {
        if (! is_array($raw)) return [];

        return array_slice(
            array_values(array_filter(
                $raw,
                fn ($item) => is_array($item)
                    && isset($item['text'])
                    && is_string($item['text'])
                    && strlen(trim($item['text'])) > 0
            )),
            0,
            6
        );
    }

    private function normalizeMakeThemHappy(mixed $raw): array
    {
        if (! is_array($raw)) return ['person_a' => [], 'person_b' => []];

        $person = function (mixed $p): array {
            if (! is_array($p)) return [];
            return [
                'name' => (string) ($p['name'] ?? ''),
                'tips' => array_values(array_filter(
                    (array) ($p['tips'] ?? []),
                    fn ($v) => is_string($v) && strlen(trim($v)) > 0
                )),
            ];
        };

        return [
            'person_a' => $person($raw['person_a'] ?? null),
            'person_b' => $person($raw['person_b'] ?? null),
        ];
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

DEPTH & QUALITY STANDARDS (this must read like a professional relationship analyst):
- Be SPECIFIC. Quote or reference real moments, topics, and phrases from the chat. Never write vague filler.
- Fully populate EVERY field for BOTH people — real names, 3-4 sentence style summaries, at least 3 concrete strengths each, and a genuine growth edge. Empty or one-word fields are unacceptable.
- Score chemistry HONESTLY on the evidence: consider responsiveness, balance, warmth, humour, depth of topics, and consistency. A friendly, engaged chat should score high (70-95); only score low (<30) when the conversation is genuinely cold, one-sided, or sparse. Do not default to a low score.
- Insights should feel personal and earned — the reader should think "wow, it really understood us".
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

    /**
     * Core half of the report schema (chemistry, interests, communication
     * style, conflicts, memories, word stats, summary). Kept in its own call
     * so the output stays comfortably under the model's token ceiling.
     */
    private function buildCoreAnalysisPrompt(array $messages, string $platform): string
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

  "top_words": [
    {
      "word": "<a meaningful word or short phrase they genuinely use a lot (skip trivial stop-words like 'the', 'and'; keep emojis or expressions if they're characteristic)>",
      "count": <approximate number of times it appears across the whole conversation>
    }
    ... (8-12 items, ordered from most to least frequent)
  ],

  "most_positive": {
    "name": "<exact display name of whichever person brings MORE positivity, warmth, and encouragement to the conversation>",
    "reason": "<2-3 warm sentences explaining, with evidence from the chat, why this person radiates more positivity — without putting the other person down>"
  },

  "conversation_summary": "<ONE single, warm sentence that captures the essence of this whole conversation and relationship>"
}

CONVERSATION ({$messageCount} messages):
{$formatted}

{$this->languageReminder()}
PROMPT;
    }

    /**
     * Extended half of the report schema (activities, connection questions,
     * love languages, sweet messages, make-them-happy tips). Split out from
     * the core prompt for the same token-ceiling headroom reasons.
     */
    private function buildExtendedAnalysisPrompt(array $messages, string $platform): string
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
  "activity_suggestions": [
    {
      "activity": "<a specific, personalised suggestion>",
      "reason": "<1 sentence connecting this to something real in their conversation>",
      "vibe": "<cosy|adventurous|creative|relaxing|social>"
    }
    ... (3-5 suggestions, all grounded in the actual chat content)
  ],

  "connection_questions": [
    {
      "question": "<a warm, deep, open-ended question the two people can ask each other to grow closer — inspired by a real topic, memory, or unspoken theme from their chat. Make it heartfelt and specific to THEM, not generic>",
      "why": "<one short sentence on how this question helps them understand each other more deeply>"
    }
    ... (exactly 5 questions, ranging from playful to emotionally deep, all grounded in their actual conversation)
  ],

  "love_languages": {
    "person_a": {
      "name": "<exact display name of person 1>",
      "primary": "<their likely primary love language inferred from the chat — e.g. 'words of affirmation', 'quality time', 'acts of service', 'physical touch', 'gifts'>",
      "how_to_show_love": ["<concrete, specific way to make THIS person feel loved, grounded in the chat>", "<another concrete way>", "<a third way>"]
    },
    "person_b": {
      "name": "<exact display name of person 2>",
      "primary": "<their likely primary love language>",
      "how_to_show_love": ["<concrete way>", "<another>", "<a third>"]
    }
  },

  "sweet_messages": [
    {
      "text": "<a warm, ready-to-send heartfelt message one partner could send the other, grounded in a real shared memory, joke, or theme from their chat. Sound natural and personal, not generic>",
      "occasion": "<good morning | appreciation | miss you | apology | just because | encouragement>"
    }
    ... (exactly 4 messages covering different occasions)
  ],

  "make_them_happy": {
    "person_a": {
      "name": "<exact display name of person 1>",
      "tips": ["<a small, concrete thing that clearly brightens THIS person's mood based on the chat>", "<another>", "<a third>"]
    },
    "person_b": {
      "name": "<exact display name of person 2>",
      "tips": ["<concrete thing>", "<another>", "<a third>"]
    }
  }
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
Extract a RICH mini-summary as JSON — this will be merged into a full report later, so capture
per-person signals carefully. Identify the two participants by their exact display names.

{
  "participants": ["<exact name of person 1>", "<exact name of person 2>"],
  "topics_discussed": ["<specific topic 1>", "<specific topic 2>", "..."],
  "emotional_tone": "<positive|neutral|tense|playful|mixed>",
  "per_person": {
    "<name of person 1>": {
      "observed_traits": ["<how they communicate here>", "..."],
      "initiates": <true|false>,
      "response_length": "<short|medium|long>",
      "emoji_usage": "<rare|occasional|frequent>",
      "notable_strength": "<one concrete strength shown in this chunk>"
    },
    "<name of person 2>": {
      "observed_traits": ["<how they communicate here>", "..."],
      "initiates": <true|false>,
      "response_length": "<short|medium|long>",
      "emoji_usage": "<rare|occasional|frequent>",
      "notable_strength": "<one concrete strength shown in this chunk>"
    }
  },
  "interesting_exchanges": ["<verbatim quote or vivid description of a notable moment>"],
  "conflicts_noted": ["<brief neutral description of any friction, if any>"],
  "shared_references": ["<inside joke, shared memory, or recurring theme>"],
  "frequent_words": [ { "word": "<meaningful word/phrase used a lot in this chunk, skip trivial stop-words>", "count": <occurrences in this chunk> } ],
  "more_positive_here": "<exact name of whoever is more positive in this chunk, or 'balanced'>"
}

CHUNK {$index}/{$total}:
{$formatted}
PROMPT;
    }

    /**
     * Core half of the reduce step. Split from the extended half for the
     * same token-ceiling headroom reasons as buildCoreAnalysisPrompt().
     */
    private function buildCoreReducePrompt(array $chunkSummaries, int $totalMessages): string
    {
        $summariesJson = json_encode($chunkSummaries, JSON_PRETTY_PRINT);
        $chunkCount    = count($chunkSummaries);

        return <<<PROMPT
You have {$totalMessages} messages summarised across the following {$chunkCount} chunk analyses.
Synthesise them into a FINAL, complete and PROFESSIONAL report using EXACTLY this JSON schema.
The chunks include a "per_person" object per chunk — AGGREGATE those signals (traits, who initiates,
response length, emoji usage, strengths) to build a rich communication_style for BOTH people. You MUST
fully populate person_a and person_b with their real names — never leave them empty or generic.

{
  "chemistry_score": <integer 1-100, justified by the evidence across all chunks>,
  "common_interests": ["<specific interest>", "... (3-8 items)"],
  "communication_style": {
    "person_a": {
      "name": "<exact display name of person 1>",
      "style_summary": "<3-4 sentences: how they express themselves, emotional tone, what they value>",
      "strengths": ["<specific strength 1>", "<specific strength 2>", "<specific strength 3>"],
      "growth_edge": "<one gentle, constructive observation>",
      "initiates_conversations": <true|false>,
      "typical_response_length": "<short|medium|long>",
      "emoji_usage": "<rare|occasional|frequent>"
    },
    "person_b": {
      "name": "<exact display name of person 2>",
      "style_summary": "<3-4 sentences>",
      "strengths": ["<specific strength 1>", "<specific strength 2>", "<specific strength 3>"],
      "growth_edge": "<one gentle observation>",
      "initiates_conversations": <true|false>,
      "typical_response_length": "<short|medium|long>",
      "emoji_usage": "<rare|occasional|frequent>"
    }
  },
  "misunderstanding_resolver": { "conflicts_detected": <int>, "resolutions": [ { "original_tension": "...", "likely_need_a": "...", "likely_need_b": "...", "reframe": "..." } ] },
  "memory_box": [ { "type": "<funny|sweet|milestone>", "moment": "<1-2 sentences>", "quote": "<closest quote>" } ],
  "top_words": [ { "word": "<meaningful frequent word/phrase, skip trivial stop-words>", "count": <approx total occurrences> } (8-12 items, most to least frequent, aggregated across all chunks) ],
  "most_positive": { "name": "<exact name of whoever brings more positivity>", "reason": "<2-3 warm sentences with evidence, without putting the other down>" },
  "conversation_summary": "<ONE single warm sentence capturing the essence of the whole conversation>"
}

Use the chunk data as evidence. Do not repeat information — synthesise it into deep, specific insights.

CHUNK SUMMARIES:
{$summariesJson}

{$this->languageReminder()}
PROMPT;
    }

    /**
     * Extended half of the reduce step (activities, connection questions,
     * love languages, sweet messages, make-them-happy tips).
     */
    private function buildExtendedReducePrompt(array $chunkSummaries, int $totalMessages): string
    {
        $summariesJson = json_encode($chunkSummaries, JSON_PRETTY_PRINT);
        $chunkCount    = count($chunkSummaries);

        return <<<PROMPT
You have {$totalMessages} messages summarised across the following {$chunkCount} chunk analyses.
Synthesise them into a FINAL, complete and PROFESSIONAL report using EXACTLY this JSON schema.

{
  "activity_suggestions": [ { "activity": "...", "reason": "...", "vibe": "<cosy|adventurous|creative|relaxing|social>" } ],
  "connection_questions": [ { "question": "<a heartfelt, deep, open-ended question grounded in their real topics/memories that helps them grow closer>", "why": "<one short sentence on how it deepens their bond>" } ],
  "love_languages": { "person_a": { "name": "...", "primary": "...", "how_to_show_love": ["...", "..."] }, "person_b": { "name": "...", "primary": "...", "how_to_show_love": ["...", "..."] } },
  "sweet_messages": [ { "text": "<ready-to-send heartfelt message grounded in a real shared memory/theme>", "occasion": "<good morning|appreciation|miss you|apology|just because|encouragement>" } ],
  "make_them_happy": { "person_a": { "name": "...", "tips": ["...", "..."] }, "person_b": { "name": "...", "tips": ["...", "..."] } }
}

Use the chunk data as evidence. Do not repeat information — synthesise it into deep, specific insights.

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
