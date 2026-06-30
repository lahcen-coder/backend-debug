<?php

namespace App\Services\AI;

class PromptBuilder
{
    public static function systemPrompt(): string
    {
        return <<<PROMPT
You are "Debug Together" — a warm, emotionally intelligent relationship enhancer.

CORE RULES (never violate):
1. NEVER assign blame or declare a winner/loser.
2. NEVER judge, criticize, or shame either person.
3. ALWAYS reframe conflict neutrally — focus on shared understanding and repair.
4. Tone: supportive, gentle, encouraging — like a kind mutual friend.
5. Output STRICT valid JSON matching the requested schema. No prose outside JSON.
6. If you detect signs of abuse, threats, coercion, or self-harm, set "safety_flag": true and do NOT provide relationship coaching — only acknowledge difficulty.
PROMPT;
    }

    public static function safetyCheckPrompt(array $messages): string
    {
        $sample = self::formatSample($messages, 50);
        return <<<PROMPT
Analyze the following conversation sample for any signs of: abuse, threats, coercion, controlling behavior, or self-harm signals.

Respond in JSON:
{"safety_flag": true|false, "reason": "brief reason if flagged, else null"}

CONVERSATION SAMPLE:
{$sample}
PROMPT;
    }

    public static function fullAnalysisPrompt(array $messages): string
    {
        $formatted = self::formatMessages($messages);
        return <<<PROMPT
Analyze the following conversation between two people. Extract insights in this EXACT JSON schema:

{
  "chemistry_score": <0-100 integer>,
  "chemistry_breakdown": {
    "responsiveness": <0-100>,
    "balance": <0-100>,
    "positivity": <0-100>,
    "consistency": <0-100>
  },
  "common_ground": {
    "summary": "<2-3 warm sentences about what unites them>",
    "topics": ["<topic1>", "<topic2>", ...],
    "shared_interests": ["<interest1>", ...],
    "inside_jokes": ["<joke/reference 1>", ...]
  },
  "memory_box": {
    "first_message": {"sender": "<name>", "text": "<text>"},
    "funniest_moments": [{"text": "<quote>", "context": "<brief>"}],
    "sweetest_moments": [{"text": "<quote>", "context": "<brief>"}],
    "milestones": ["<milestone description>"]
  },
  "icebreakers": [
    {"type": "question|activity|date_idea", "text": "<personalized suggestion>"}
  ],
  "safety_flag": false
}

CONVERSATION:
{$formatted}
PROMPT;
    }

    public static function chunkMapPrompt(array $messages, int $chunkIndex, int $totalChunks): string
    {
        $formatted = self::formatMessages($messages);
        return <<<PROMPT
This is chunk {$chunkIndex} of {$totalChunks} from a longer conversation.
Extract a mini-summary as JSON:
{
  "topics": ["..."],
  "interesting_exchanges": ["<verbatim quote or description>"],
  "tone": "<positive|neutral|tense|mixed>",
  "notable_moments": ["..."]
}

CHUNK:
{$formatted}
PROMPT;
    }

    public static function chunkReducePrompt(array $chunkSummaries, int $messageCount): string
    {
        $summaries = json_encode($chunkSummaries, JSON_PRETTY_PRINT);
        return <<<PROMPT
You have {$messageCount} messages summarized across the following chunk analyses.
Synthesize them into a FINAL complete analysis using the same JSON schema as a full analysis:

{
  "chemistry_score": ...,
  "chemistry_breakdown": {...},
  "common_ground": {...},
  "memory_box": {...},
  "icebreakers": [...],
  "safety_flag": false
}

CHUNK SUMMARIES:
{$summaries}
PROMPT;
    }

    private static function formatMessages(array $messages): string
    {
        return implode("\n", array_map(
            fn($m) => "[{$m['timestamp']}] {$m['sender']}: {$m['text']}",
            $messages
        ));
    }

    private static function formatSample(array $messages, int $n): string
    {
        $sample = array_slice($messages, 0, $n);
        return self::formatMessages($sample);
    }
}
