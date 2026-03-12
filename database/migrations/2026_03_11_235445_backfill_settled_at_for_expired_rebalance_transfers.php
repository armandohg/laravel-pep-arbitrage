<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Historical transfers predate the tracking system and have no withdrawal_id/tx_hash.
        // Any that are past their expires_at are assumed to have completed successfully —
        // settle them at expires_at so they don't appear as unsettled in track-transfers.
        DB::table('rebalance_transfers')
            ->whereNull('settled_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update([
                'settled_at' => DB::raw('expires_at'),
                'withdrawal_status' => 'completed',
            ]);
    }

    public function down(): void
    {
        // Not reversible — we cannot know which records were backfilled vs genuinely settled.
    }
};
