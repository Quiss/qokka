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
        Schema::table('source_channels', function (Blueprint $table) {
            $table->foreignId('preferred_collector_telegram_account_id')
                ->nullable()
                ->after('collector_telegram_account_id')
                ->constrained('telegram_accounts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('source_channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_collector_telegram_account_id');
        });
    }
};
