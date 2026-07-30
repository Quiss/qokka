<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_owner_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_account_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('status', 32)->default('pending');
            $table->string('deduplication_key', 255)->nullable();
            $table->jsonb('payload');
            $table->jsonb('result')->nullable();
            $table->smallInteger('priority')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['telegram_account_id', 'deduplication_key'],
                'telegram_owner_commands_account_dedupe_unique',
            );
            $table->index(
                ['telegram_account_id', 'status', 'available_at', 'priority'],
                'telegram_owner_commands_claim_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_owner_commands');
    }
};
