<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel Cashier 15.x compatible subscription tables.
 *
 * Run `composer require laravel/cashier` and add the `Billable` trait
 * to your User model to unlock the full Cashier ORM on top of this schema.
 *
 * @see https://laravel.com/docs/11.x/billing
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── subscriptions ─────────────────────────────────────────────────────
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // "type" is Cashier 15+'s replacement for the old "name" column.
            // Typically "default" for the primary subscription.
            $table->string('type');

            // Stripe Subscription ID (sub_xxxx). Unique per active subscription.
            $table->string('stripe_id')->unique();

            // Stripe subscription status: active | trialing | past_due |
            //   incomplete | incomplete_expired | canceled | unpaid | paused
            $table->string('stripe_status');

            // The default Stripe Price ID attached to this subscription.
            // May be null for multi-price subscriptions (see subscription_items).
            $table->string('stripe_price')->nullable();

            // Quantity for per-seat billing. Null for flat-rate plans.
            $table->unsignedInteger('quantity')->nullable();

            // Trial & cancellation timestamps
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'stripe_status']);
        });

        // ── subscription_items ────────────────────────────────────────────────
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            // Stripe Subscription Item ID (si_xxxx)
            $table->string('stripe_id')->unique();

            // The Stripe Product ID (prod_xxxx) this item belongs to.
            $table->string('stripe_product')->nullable();

            // The Stripe Price ID (price_xxxx) for this line item.
            $table->string('stripe_price');

            $table->unsignedInteger('quantity')->nullable();

            $table->timestamps();

            $table->index('stripe_price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
    }
};
