<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE source_messages
            SET telegram_account_id = source_channels.collector_telegram_account_id
            FROM source_channels
            WHERE source_channels.id = source_messages.source_channel_id
              AND source_messages.telegram_account_id IS NULL
              AND source_channels.collector_telegram_account_id IS NOT NULL
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
