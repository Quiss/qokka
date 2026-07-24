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
        Schema::table('content_plans', function (Blueprint $table) {
            $table->text('failure_reason')->nullable();
            $table->timestamp('failed_at')->nullable();
        });

        Schema::table('planned_posts', function (Blueprint $table) {
            $table->text('failure_reason')->nullable();
            $table->timestamp('failed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('planned_posts', function (Blueprint $table) {
            $table->dropColumn(['failure_reason', 'failed_at']);
        });

        Schema::table('content_plans', function (Blueprint $table) {
            $table->dropColumn(['failure_reason', 'failed_at']);
        });
    }
};
