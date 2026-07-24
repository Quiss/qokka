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
        Schema::create('source_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_channel_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('external_message_id');
            $table->string('telegram_grouped_id')->nullable();
            $table->text('text')->nullable();
            $table->jsonb('entities')->nullable();
            $table->jsonb('metrics')->nullable();
            $table->jsonb('raw_payload')->nullable();
            $table->timestamp('posted_at')->index();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['source_channel_id', 'external_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_messages');
    }
};
