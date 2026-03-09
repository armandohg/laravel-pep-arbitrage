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
        Schema::table('arbitrage_settings', function (Blueprint $table): void {
            $table->boolean('rebalance_enabled')->default(true)->after('execute_orders');
        });
    }

    public function down(): void
    {
        Schema::table('arbitrage_settings', function (Blueprint $table): void {
            $table->dropColumn('rebalance_enabled');
        });
    }
};
