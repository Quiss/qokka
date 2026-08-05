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
            $table->unique(
                ['mediable_type', 'mediable_id', 'ingest_key'],
                'media_assets_mediable_ingest_key_unique',
            );
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropUnique('media_assets_ingest_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->unique('ingest_key');
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropUnique('media_assets_mediable_ingest_key_unique');
        });
    }
};
