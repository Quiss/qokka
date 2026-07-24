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
        Schema::create('source_channel_source_group', function (Blueprint $table) {
            $table->foreignId('source_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_group_id')->constrained()->cascadeOnDelete();

            $table->primary(['source_channel_id', 'source_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_channel_source_group');
    }
};
