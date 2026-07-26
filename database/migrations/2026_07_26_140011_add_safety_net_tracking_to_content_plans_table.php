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
            $table->timestamp('safety_net_started_at')->nullable()->after('ready_at');
            $table->timestamp('safety_net_refreshed_at')->nullable()->after('safety_net_started_at');
            $table->timestamp('safety_net_completed_at')->nullable()->after('safety_net_refreshed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->dropColumn([
                'safety_net_started_at',
                'safety_net_refreshed_at',
                'safety_net_completed_at',
            ]);
        });
    }
};
