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
        Schema::create('arbitrage_opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('buy_exchange');
            $table->string('sell_exchange');
            $table->decimal('amount', 20, 8);
            $table->decimal('total_buy_cost', 20, 8);
            $table->decimal('total_sell_revenue', 20, 8);
            $table->decimal('profit', 20, 8);
            $table->decimal('profit_ratio', 10, 8);
            $table->string('profit_level');
            $table->timestamps();

            $table->index('profit_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arbitrage_opportunities');
    }
};
