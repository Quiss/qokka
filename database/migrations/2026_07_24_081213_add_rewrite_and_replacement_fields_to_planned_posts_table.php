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
        Schema::table('planned_posts', function (Blueprint $table) {
            $table->foreignId('replaces_planned_post_id')
                ->nullable()
                ->after('story_candidate_id')
                ->constrained('planned_posts')
                ->nullOnDelete();
            $table->unsignedInteger('rewrite_generation')->default(0)->after('original_ai_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('planned_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_planned_post_id');
            $table->dropColumn('rewrite_generation');
        });
    }
};
