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
        Schema::create('source_channel_telegram_account', function (Blueprint $table) {
            $table->foreignId('source_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_account_id')->constrained()->cascadeOnDelete();
            $table->string('access_status')->default('unknown')->index();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->primary(['source_channel_id', 'telegram_account_id']);
            $table->index(['telegram_account_id', 'access_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_channel_telegram_account');
    }
};
