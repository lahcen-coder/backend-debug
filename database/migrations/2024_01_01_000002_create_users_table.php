<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // ── Stripe / Cashier ─────────────────────────────────────────────
            // stripe_id  → Stripe Customer ID (cus_xxxx)
            // pm_type    → Payment method brand (visa, mastercard, etc.)
            // pm_last_four → Last 4 digits of the saved payment method
            // trial_ends_at → When the Stripe trial expires (managed by Cashier)
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type', 50)->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();

            // ── Membership Tier ──────────────────────────────────────────────
            // Denormalised quick-access tier. Source of truth is the
            // active Stripe subscription. Synced via webhook.
            $table->enum('membership', ['free', 'pro'])->default('free')->index();

            // ── Plan (detailed limits & features) ───────────────────────────
            $table->foreignId('plan_id')->default(1)->constrained('plans');

            // ── Preferences ──────────────────────────────────────────────────
            $table->string('locale', 10)->default('en');
            $table->boolean('marketing_opt_in')->default(false);

            // ── GDPR Compliance ───────────────────────────────────────────────
            // consent_version     → Which version of the privacy policy was accepted
            // consented_at        → Timestamp of consent (immutable audit trail)
            // data_deletion_requested_at → GDPR right-to-erasure request timestamp
            $table->string('consent_version', 20)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('data_deletion_requested_at')->nullable();

            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
