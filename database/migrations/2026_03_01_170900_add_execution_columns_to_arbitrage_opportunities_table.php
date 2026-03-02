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
        Schema::table('arbitrage_opportunities', function (Blueprint $table) {
            $table->string('execution_status')->nullable()->after('profit_level');
            $table->timestamp('executed_at')->nullable()->after('execution_status');
            $table->string('tx_buy_id')->nullable()->after('executed_at');
            $table->string('tx_sell_id')->nullable()->after('tx_buy_id');
            $table->decimal('executed_amount', 20, 8)->nullable()->after('tx_sell_id');
            $table->decimal('executed_buy_price', 20, 8)->nullable()->after('executed_amount');
            $table->decimal('executed_sell_price', 20, 8)->nullable()->after('executed_buy_price');
            $table->text('execution_error')->nullable()->after('executed_sell_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('arbitrage_opportunities', function (Blueprint $table) {
            $table->dropColumn([
                'execution_status',
                'executed_at',
                'tx_buy_id',
                'tx_sell_id',
                'executed_amount',
                'executed_buy_price',
                'executed_sell_price',
                'execution_error',
            ]);
        });
    }
};
