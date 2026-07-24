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
        Schema::table('media_assets', function (Blueprint $table) {
            $table->foreignId('source_message_id')
                ->nullable()
                ->after('mediable_id')
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('origin_media_asset_id')
                ->nullable()
                ->after('source_message_id')
                ->constrained('media_assets')
                ->nullOnDelete();
            $table->string('preview_disk')->nullable()->after('path');
            $table->string('preview_path')->nullable()->after('preview_disk');
            $table->string('preview_mime_type')->nullable()->after('preview_path');
            $table->timestamp('preview_downloaded_at')->nullable()->after('downloaded_at');
            $table->timestamp('preview_failed_at')->nullable()->after('preview_downloaded_at');

            $table->index(['origin_media_asset_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropIndex(['origin_media_asset_id', 'sort_order']);
            $table->dropConstrainedForeignId('origin_media_asset_id');
            $table->dropConstrainedForeignId('source_message_id');
            $table->dropColumn([
                'preview_disk',
                'preview_path',
                'preview_mime_type',
                'preview_downloaded_at',
                'preview_failed_at',
            ]);
        });
    }
};
