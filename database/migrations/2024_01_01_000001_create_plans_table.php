<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('stripe_price_id')->nullable();
            $table->unsignedInteger('monthly_analyses');
            $table->unsignedInteger('max_messages_per_analysis');
            $table->unsignedBigInteger('monthly_token_budget');
            $table->unsignedInteger('monthly_assistant_messages')->default(0);
            $table->json('features')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->timestamps();
        });

        DB::table('plans')->insert([
            [
                'name' => 'Free',
                'slug' => 'free',
                'stripe_price_id' => null,
                'monthly_analyses' => 1,
                'max_messages_per_analysis' => 2000,
                'monthly_token_budget' => 50000,
                'monthly_assistant_messages' => 0,
                'features' => json_encode(['memory_box' => 'preview', 'sharing' => false]),
                'price_cents' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Plus',
                'slug' => 'plus',
                'stripe_price_id' => null,
                'monthly_analyses' => 10,
                'max_messages_per_analysis' => 20000,
                'monthly_token_budget' => 1000000,
                'monthly_assistant_messages' => 50,
                'features' => json_encode(['memory_box' => 'full', 'sharing' => true, 'pdf' => true]),
                'price_cents' => 799,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'stripe_price_id' => null,
                'monthly_analyses' => 9999,
                'max_messages_per_analysis' => 100000,
                'monthly_token_budget' => 10000000,
                'monthly_assistant_messages' => 500,
                'features' => json_encode(['memory_box' => 'full', 'sharing' => true, 'pdf' => true, 'priority_model' => true]),
                'price_cents' => 1499,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
