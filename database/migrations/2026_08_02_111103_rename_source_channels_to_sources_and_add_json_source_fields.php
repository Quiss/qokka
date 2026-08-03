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
        Schema::rename('source_channels', 'sources');
        Schema::rename('source_channel_source_group', 'source_group_source');
        Schema::rename('source_channel_telegram_account', 'source_telegram_account');

        Schema::table('source_posts', function (Blueprint $table) {
            $table->renameColumn('source_channel_id', 'source_id');
        });

        Schema::table('source_messages', function (Blueprint $table) {
            $table->renameColumn('source_channel_id', 'source_id');
        });

        Schema::table('source_group_source', function (Blueprint $table) {
            $table->renameColumn('source_channel_id', 'source_id');
        });

        Schema::table('source_telegram_account', function (Blueprint $table) {
            $table->renameColumn('source_channel_id', 'source_id');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->string('type')->default('telegram')->index();
            $table->text('endpoint_url')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->text('credentials')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->jsonb('last_sync_summary')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn([
                'type',
                'endpoint_url',
                'settings',
                'credentials',
                'last_synced_at',
                'last_sync_error',
                'last_sync_summary',
            ]);
        });

        Schema::table('source_telegram_account', function (Blueprint $table) {
            $table->renameColumn('source_id', 'source_channel_id');
        });

        Schema::table('source_group_source', function (Blueprint $table) {
            $table->renameColumn('source_id', 'source_channel_id');
        });

        Schema::table('source_messages', function (Blueprint $table) {
            $table->renameColumn('source_id', 'source_channel_id');
        });

        Schema::table('source_posts', function (Blueprint $table) {
            $table->renameColumn('source_id', 'source_channel_id');
        });

        Schema::rename('source_telegram_account', 'source_channel_telegram_account');
        Schema::rename('source_group_source', 'source_channel_source_group');
        Schema::rename('sources', 'source_channels');
    }
};
