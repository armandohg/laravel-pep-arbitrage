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
        Schema::table('spread_snapshots', function (Blueprint $table) {
            $table->string('bal_buy_exchange', 20)->nullable()->after('spread_ratio');
            $table->string('bal_sell_exchange', 20)->nullable()->after('bal_buy_exchange');
            $table->decimal('bal_spread_ratio', 10, 6)->nullable()->after('bal_sell_exchange');
            $table->decimal('bal_profit', 12, 6)->nullable()->after('bal_spread_ratio');
            $table->decimal('bal_usdt', 12, 4)->nullable()->after('bal_profit');
        });
    }

    public function down(): void
    {
        Schema::table('spread_snapshots', function (Blueprint $table) {
            $table->dropColumn(['bal_buy_exchange', 'bal_sell_exchange', 'bal_spread_ratio', 'bal_profit', 'bal_usdt']);
        });
    }
};
