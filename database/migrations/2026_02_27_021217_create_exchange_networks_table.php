<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_networks', function (Blueprint $table) {
            $table->id();
            $table->string('exchange');
            $table->string('asset');
            $table->string('network_code');
            $table->string('network_id');
            $table->string('network_name')->default('');
            $table->decimal('fee', 20, 8)->default(0);
            $table->decimal('min_amount', 20, 8)->default(0);
            $table->decimal('max_amount', 20, 8)->default(0);
            $table->boolean('deposit_enabled')->default(true);
            $table->boolean('withdraw_enabled')->default(true);
            $table->timestamps();

            $table->unique(['exchange', 'asset', 'network_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_networks');
    }
};
