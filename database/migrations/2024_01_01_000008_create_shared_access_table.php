<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_access', function (Blueprint $table) {
            $table->id();

            // The analysis being shared.
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();

            // The user who owns the analysis and is sharing it.
            $table->foreignId('owner_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // The email address the invitation was sent to.
            // The resolved user FK (shared_with_user_id) is set on acceptance.
            $table->string('invitee_email');
            $table->foreignId('shared_with_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Cryptographically random 64-char token for the invite link.
            $table->string('invite_token', 64)->unique();

            $table->enum('status', ['pending', 'accepted', 'revoked'])->default('pending');

            // Link expiry — null means no expiry (permanent share).
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->timestamps();

            $table->index(['analysis_id', 'status']);
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_access');
    }
};
