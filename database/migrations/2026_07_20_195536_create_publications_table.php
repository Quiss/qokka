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
        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_group_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('language', 10)->default('ru');
            $table->string('timezone')->default('Europe/Moscow');
            $table->text('tone_prompt');
            $table->jsonb('tone_examples')->nullable();
            $table->jsonb('forbidden_phrases')->nullable();
            $table->jsonb('content_filters')->nullable();
            $table->string('analysis_model')->nullable();
            $table->string('rewrite_model')->nullable();
            $table->time('planning_time')->default('18:00');
            $table->time('publish_window_start')->default('09:00');
            $table->time('publish_window_end')->default('23:00');
            $table->unsignedSmallInteger('min_interval_minutes')->default(90);
            $table->unsignedSmallInteger('max_interval_minutes')->default(180);
            $table->decimal('reserve_multiplier', 3, 2)->default(1.50);
            $table->unsignedSmallInteger('media_caption_limit')->default(900);
            $table->boolean('show_source_attribution')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('publications');
    }
};
