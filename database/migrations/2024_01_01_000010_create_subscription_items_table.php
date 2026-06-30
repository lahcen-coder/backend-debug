<?php

/**
 * This file is intentionally left as a no-op stub because subscription_items
 * is now created inside 2024_01_01_000003_create_subscriptions_table.php
 * in the same transaction as the parent `subscriptions` table.
 *
 * Keeping this file prevents migration numbering gaps if other migrations
 * reference sequence 000010.
 */

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Created alongside subscriptions in migration 000003.
    }

    public function down(): void
    {
        // Dropped alongside subscriptions in migration 000003.
    }
};
