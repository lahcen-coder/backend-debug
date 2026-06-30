<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('chemistry_score')->default(0);
            $table->json('chemistry_breakdown')->nullable();
            $table->json('common_ground')->nullable();
            $table->json('memory_box')->nullable();
            $table->json('misunderstandings')->nullable();
            $table->json('icebreakers')->nullable();
            $table->boolean('safety_flag')->default(false);
            $table->string('ai_model')->nullable();
            $table->json('raw_ai_output')->nullable(); // TTL via scheduled purge
            $table->timestamp('raw_ai_expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
