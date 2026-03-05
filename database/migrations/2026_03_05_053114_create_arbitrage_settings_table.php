<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('arbitrage_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('discovery_interval')->default(5);
            $table->decimal('min_profit_ratio', 10, 6)->default(0.003);
            $table->integer('sustain_duration')->default(10);
            $table->integer('sustain_interval')->default(2);
            $table->decimal('stability', 10, 4)->default(0.5);
            $table->decimal('min_amount', 10, 4)->default(0);
            $table->boolean('execute_orders')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arbitrage_settings');
    }
};
