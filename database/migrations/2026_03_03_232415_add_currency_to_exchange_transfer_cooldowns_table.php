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
        Schema::table('exchange_transfer_cooldowns', function (Blueprint $table) {
            $table->dropUnique(['exchange']);
            $table->string('currency', 20)->after('exchange')->default('');
            $table->unique(['exchange', 'currency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_transfer_cooldowns', function (Blueprint $table) {
            $table->dropUnique(['exchange', 'currency']);
            $table->dropColumn('currency');
            $table->unique(['exchange']);
        });
    }
};
