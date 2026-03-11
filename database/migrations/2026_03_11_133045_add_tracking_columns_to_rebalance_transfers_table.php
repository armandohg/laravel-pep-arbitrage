<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rebalance_transfers', function (Blueprint $table) {
            $table->string('withdrawal_id')->nullable()->after('network');
            $table->string('withdrawal_status')->nullable()->after('withdrawal_id');
            $table->string('tx_hash')->nullable()->after('withdrawal_status');
            $table->timestamp('deposit_confirmed_at')->nullable()->after('tx_hash');
        });
    }

    public function down(): void
    {
        Schema::table('rebalance_transfers', function (Blueprint $table) {
            $table->dropColumn(['withdrawal_id', 'withdrawal_status', 'tx_hash', 'deposit_confirmed_at']);
        });
    }
};
