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
        Schema::create('spread_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('buy_exchange', 20);
            $table->string('sell_exchange', 20);
            $table->decimal('spread_ratio', 10, 6);
            $table->timestamp('recorded_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spread_snapshots');
    }
};
