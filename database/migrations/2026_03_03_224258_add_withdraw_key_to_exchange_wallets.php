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
        Schema::table('exchange_wallets', function (Blueprint $table): void {
            $table->string('withdraw_key')->nullable()->after('memo');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_wallets', function (Blueprint $table): void {
            $table->dropColumn('withdraw_key');
        });
    }
};
