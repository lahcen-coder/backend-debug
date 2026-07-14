<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The original `clean_payload_hash` column was globally UNIQUE. Combined with
 * markFailed() never clearing the hash, this meant:
 *   1. Once ANY analysis of a given conversation failed, that exact conversation
 *      could never be submitted again by anyone — the INSERT would violate the
 *      unique constraint and throw an uncaught QueryException (raw HTTP 500).
 *   2. Two different users submitting identical sample text (e.g. during
 *      testing) would collide with each other, not just with themselves.
 *
 * Fix: scope uniqueness to (user_id, clean_payload_hash) instead of global, and
 * clear the hash on every currently `failed` row so previously stuck
 * conversations become resubmittable immediately after this migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Free up any hash currently pinned by a permanently-failed analysis.
        DB::table('analyses')
            ->where('status', 'failed')
            ->whereNotNull('clean_payload_hash')
            ->update(['clean_payload_hash' => null]);

        Schema::table('analyses', function (Blueprint $table) {
            $table->dropUnique(['clean_payload_hash']);
        });

        Schema::table('analyses', function (Blueprint $table) {
            $table->unique(['user_id', 'clean_payload_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'clean_payload_hash']);
        });

        Schema::table('analyses', function (Blueprint $table) {
            $table->unique('clean_payload_hash');
        });
    }
};
