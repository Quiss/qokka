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
        Schema::table('publications', function (Blueprint $table) {
            $table->boolean('safety_net_enabled')->default(true)->after('planning_time');
            $table->time('safety_net_cutoff_time')->default('00:00')->after('safety_net_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropColumn(['safety_net_enabled', 'safety_net_cutoff_time']);
        });
    }
};
