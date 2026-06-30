<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // gemini | openai
            $table->string('model');
            $table->string('operation'); // full_analysis | safety_check | chunk_map | chunk_reduce | assistant
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status'); // success | failed | invalid_json
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('analysis_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
