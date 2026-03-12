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
        Schema::table('arbitrage_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('transfer_expiry_hours')->default(3)->after('rebalance_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('arbitrage_settings', function (Blueprint $table) {
            $table->dropColumn('transfer_expiry_hours');
        });
    }
};
