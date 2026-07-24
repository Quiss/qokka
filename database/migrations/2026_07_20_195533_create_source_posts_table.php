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
        Schema::create('source_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_channel_id')->constrained()->cascadeOnDelete();
            $table->string('canonical_key');
            $table->string('telegram_grouped_id')->nullable();
            $table->text('text')->nullable();
            $table->text('normalized_text')->nullable();
            $table->string('source_url')->nullable();
            $table->jsonb('metrics')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamp('posted_at')->index();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['source_channel_id', 'canonical_key']);
            $table->index(['source_channel_id', 'posted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_posts');
    }
};
