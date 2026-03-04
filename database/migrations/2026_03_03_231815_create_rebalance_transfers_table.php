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
        Schema::create('rebalance_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('from_exchange', 50);
            $table->string('to_exchange', 50);
            $table->string('currency', 20);
            $table->decimal('amount', 20, 8);
            $table->string('network', 50);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['to_exchange', 'currency', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rebalance_transfers');
    }
};
