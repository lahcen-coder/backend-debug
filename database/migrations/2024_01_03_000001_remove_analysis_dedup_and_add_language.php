<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two changes requested together:
 *
 * 1. Remove submission deduplication entirely. Previously, resubmitting the
 *    exact same conversation returned a cached report instead of running a
 *    fresh analysis, which was surprising to users who wanted an updated
 *    read every time. Drop the (user_id, clean_payload_hash) unique index
 *    added in the previous migration — the column itself is kept (nullable,
 *    non-unique) purely for future analytics, but the app should no longer
 *    write to or query it for dedup purposes.
 *
 * 2. Persist the report language ('english' | 'spanish' | 'darija') on the
 *    Analysis record itself, so the frontend can render section headings and
 *    other static UI copy in the correct language after the fact (it was
 *    previously only passed to the AI prompt and never stored).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'clean_payload_hash']);
        });

        Schema::table('analyses', function (Blueprint $table) {
            $table->string('language', 20)->default('english')->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropColumn('language');
        });

        Schema::table('analyses', function (Blueprint $table) {
            $table->unique(['user_id', 'clean_payload_hash']);
        });
    }
};
