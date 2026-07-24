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
        Schema::create('planned_post_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planned_post_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('text');
            $table->jsonb('risk_flags')->nullable();
            $table->text('instruction')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ai_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['planned_post_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planned_post_revisions');
    }
};
