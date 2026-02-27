<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_routes', function (Blueprint $table) {
            $table->id();
            $table->string('from_exchange', 50);
            $table->string('to_exchange', 50);
            $table->string('asset', 20);
            $table->string('network_code', 20);
            $table->foreignId('wallet_id')->constrained('exchange_wallets');
            $table->decimal('fee', 20, 8)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['from_exchange', 'to_exchange', 'asset', 'network_code'], 'transfer_routes_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_routes');
    }
};
