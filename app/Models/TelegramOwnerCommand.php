<?php

namespace App\Models;

use App\TelegramOwnerCommandStatus;
use App\TelegramOwnerCommandType;
use Database\Factories\TelegramOwnerCommandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $telegram_account_id
 * @property TelegramOwnerCommandType $type
 * @property TelegramOwnerCommandStatus $status
 * @property string|null $deduplication_key
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $result
 * @property int $priority
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon $available_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $last_error
 * @property-read TelegramAccount $telegramAccount
 */
#[Fillable([
    'telegram_account_id',
    'type',
    'status',
    'deduplication_key',
    'payload',
    'result',
    'priority',
    'attempts',
    'max_attempts',
    'available_at',
    'started_at',
    'finished_at',
    'last_error',
])]
class TelegramOwnerCommand extends Model
{
    /** @use HasFactory<TelegramOwnerCommandFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
        'priority' => 0,
        'attempts' => 0,
        'max_attempts' => 3,
    ];

    /** @return BelongsTo<TelegramAccount, $this> */
    public function telegramAccount(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class);
    }

    protected function casts(): array
    {
        return [
            'type' => TelegramOwnerCommandType::class,
            'status' => TelegramOwnerCommandStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'priority' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
