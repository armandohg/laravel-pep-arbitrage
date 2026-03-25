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
        Schema::create('exchange_reserves', function (Blueprint $table) {
            $table->id();
            $table->string('exchange');
            $table->string('currency');
            $table->decimal('min_amount', 20, 8)->default(0);
            $table->timestamps();

            $table->unique(['exchange', 'currency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_reserves');
    }
};
