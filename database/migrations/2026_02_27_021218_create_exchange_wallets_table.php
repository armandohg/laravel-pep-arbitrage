<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_wallets', function (Blueprint $table) {
            $table->id();
            $table->string('exchange');
            $table->string('asset');
            $table->string('network_code');
            $table->text('address');
            $table->string('memo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['exchange', 'asset', 'network_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_wallets');
    }
};
