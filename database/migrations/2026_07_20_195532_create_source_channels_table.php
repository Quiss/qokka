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
        Schema::create('source_channels', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_peer_id')->nullable()->unique();
            $table->string('username')->nullable()->unique();
            $table->string('title');
            $table->decimal('weight', 5, 2)->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('last_backfilled_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_channels');
    }
};
