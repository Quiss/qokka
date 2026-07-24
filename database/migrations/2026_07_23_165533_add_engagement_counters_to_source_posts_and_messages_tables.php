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
        Schema::table('source_posts', function (Blueprint $table) {
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('forwards')->default(0);
            $table->unsignedBigInteger('reactions')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
        });

        Schema::table('source_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('forwards')->default(0);
            $table->unsignedBigInteger('reactions')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('source_posts', function (Blueprint $table) {
            $table->dropColumn(['views', 'forwards', 'reactions', 'comments']);
        });

        Schema::table('source_messages', function (Blueprint $table) {
            $table->dropColumn(['views', 'forwards', 'reactions', 'comments']);
        });
    }
};
