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
        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->unique();
            $table->bigInteger('telegram_user_id')->nullable()->unique();
            $table->string('username')->nullable();
            $table->string('phone_hint')->nullable();
            $table->string('status')->default('pending')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_accounts');
    }
};
