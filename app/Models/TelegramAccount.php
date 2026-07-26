<?php

namespace App\Models;

use App\TelegramAccountStatus;
use Database\Factories\TelegramAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property int|null $telegram_user_id
 * @property string|null $username
 * @property string|null $phone_hint
 * @property TelegramAccountStatus $status
 * @property bool $is_active
 * @property Carbon|null $authorized_at
 * @property Carbon|null $last_seen_at
 * @property string|null $last_error
 * @property-read Collection<int, SourceChannel> $sourceChannels
 * @property-read Collection<int, SourceChannel> $assignedSourceChannels
 */
#[Fillable(['uuid', 'name', 'telegram_user_id', 'username', 'phone_hint', 'status', 'is_active', 'authorized_at', 'last_seen_at', 'last_error'])]
class TelegramAccount extends Model
{
    /** @use HasFactory<TelegramAccountFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (TelegramAccount $account): void {
            $account->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsToMany<SourceChannel, $this> */
    public function sourceChannels(): BelongsToMany
    {
        return $this->belongsToMany(SourceChannel::class)
            ->withPivot(['access_status', 'last_checked_at', 'last_error'])
            ->withTimestamps();
    }

    /** @return HasMany<SourceChannel, $this> */
    public function assignedSourceChannels(): HasMany
    {
        return $this->hasMany(SourceChannel::class, 'collector_telegram_account_id');
    }

    public function isHeartbeatFresh(): bool
    {
        return $this->last_seen_at?->greaterThanOrEqualTo(now()->subMinutes(3)) ?? false;
    }

    public function isCollectorReady(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($this->status) {
            TelegramAccountStatus::Connected => $this->isHeartbeatFresh(),
            TelegramAccountStatus::Authorized => $this->last_seen_at === null,
            default => false,
        };
    }

    /**
     * @param  Builder<TelegramAccount>  $query
     * @return Builder<TelegramAccount>
     */
    public function scopeCollectorReady(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query->where('status', TelegramAccountStatus::Connected)
                            ->where('last_seen_at', '>=', now()->subMinutes(3));
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', TelegramAccountStatus::Authorized)
                            ->whereNull('last_seen_at');
                    });
            });
    }

    protected function casts(): array
    {
        return [
            'status' => TelegramAccountStatus::class,
            'is_active' => 'boolean',
            'authorized_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
