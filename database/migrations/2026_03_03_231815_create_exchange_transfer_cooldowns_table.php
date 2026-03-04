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
        Schema::create('exchange_transfer_cooldowns', function (Blueprint $table) {
            $table->id();
            $table->string('exchange', 50)->unique();
            $table->unsignedSmallInteger('cooldown_minutes');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_transfer_cooldowns');
    }
};
