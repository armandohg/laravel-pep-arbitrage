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
        Schema::dropIfExists('exchange_transfer_cooldowns');
    }

    public function down(): void
    {
        Schema::create('exchange_transfer_cooldowns', function (Blueprint $table) {
            $table->id();
            $table->string('exchange');
            $table->string('currency');
            $table->unsignedSmallInteger('cooldown_minutes')->default(60);
            $table->timestamps();
            $table->unique(['exchange', 'currency']);
        });
    }
};
