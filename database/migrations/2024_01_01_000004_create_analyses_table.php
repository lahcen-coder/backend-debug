<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // ── Chat Metadata ─────────────────────────────────────────────────
            // partner_name is stored as a SHA-256 hash of the contact label
            // to preserve privacy (never store raw contact names server-side).
            $table->string('partner_name')->nullable()->comment('SHA-256 hash of the contact label for privacy');

            // Platform the conversation was exported from.
            $table->enum('platform', ['instagram', 'whatsapp']);

            // ── Processing State ──────────────────────────────────────────────
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending');

            // SHA-256 of the cleaned, normalised payload.
            // Used for deduplication — prevents re-analyzing the same chat.
            $table->string('clean_payload_hash', 64)->unique()->nullable();

            // ── AI Output ─────────────────────────────────────────────────────
            // Which AI provider generated this report.
            $table->enum('ai_provider', ['gemini', 'openai'])->nullable();

            // Full structured AI report stored as JSON.
            // Schema: { chemistry_score, insights[], communication_patterns,
            //           red_flags[], strengths[], summary, generated_at }
            $table->json('report_data')->nullable();

            // ── Message Statistics ────────────────────────────────────────────
            $table->unsignedInteger('message_count')->default(0);

            // ── Error Handling ────────────────────────────────────────────────
            $table->text('failure_reason')->nullable();

            // Set to true if the AI safety classifier flagged the content.
            $table->boolean('safety_flag')->default(false);

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('created_at');
            $table->index('platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analyses');
    }
};
