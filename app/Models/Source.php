<?php

namespace App\Models;

use App\SourceType;
use App\TelegramSourceAccessStatus;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property SourceType $type
 * @property int|null $collector_telegram_account_id
 * @property int|null $preferred_collector_telegram_account_id
 * @property int|null $telegram_peer_id
 * @property string|null $username
 * @property string $title
 * @property numeric-string $weight
 * @property bool $is_active
 * @property string|null $endpoint_url
 * @property array<string, mixed> $settings
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $last_sync_summary
 * @property Carbon|null $last_event_at
 * @property Carbon|null $last_backfilled_at
 * @property Carbon|null $last_synced_at
 * @property string|null $last_sync_error
 * @property int $posts_last_day_count
 * @property int $views_last_day
 * @property int $forwards_last_day
 * @property int $reactions_last_day
 * @property int $comments_last_day
 * @property-read Collection<int, SourceGroup> $sourceGroups
 * @property-read Collection<int, TelegramAccount> $telegramAccounts
 * @property-read TelegramAccount|null $collectorTelegramAccount
 * @property-read TelegramAccount|null $preferredCollectorTelegramAccount
 */
#[Fillable([
    'type',
    'collector_telegram_account_id',
    'preferred_collector_telegram_account_id',
    'telegram_peer_id',
    'username',
    'title',
    'weight',
    'is_active',
    'last_event_at',
    'last_backfilled_at',
    'metadata',
    'endpoint_url',
    'settings',
    'credentials',
    'last_synced_at',
    'last_sync_error',
    'last_sync_summary',
])]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected $attributes = [
        'type' => 'telegram',
        'weight' => 1,
        'is_active' => true,
        'settings' => '{}',
    ];

    protected $hidden = ['credentials'];

    protected static function booted(): void
    {
        static::creating(function (Source $source): void {
            if (! $source->isTelegram()) {
                return;
            }

            $source->username = filled($source->username)
                ? self::normalizeUsername((string) $source->username)
                : null;
            $source->title = filled($source->title)
                ? $source->title
                : ($source->username ? '@'.$source->username : (string) $source->telegram_peer_id);
        });

        static::updating(function (Source $source): void {
            if ($source->isTelegram() && $source->isDirty('username') && filled($source->username)) {
                $source->username = self::normalizeUsername((string) $source->username);
            }
        });
    }

    public static function normalizeUsername(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('#^https?://t\.me/#i', '', $value) ?? $value;

        return ltrim(Str::before($value, '/'), '@');
    }

    public function isTelegram(): bool
    {
        return $this->type === SourceType::Telegram;
    }

    public function isJsonCollection(): bool
    {
        return $this->type === SourceType::JsonCollection;
    }

    public function authorization(): ?string
    {
        $authorization = data_get($this->credentials, 'authorization');

        return is_string($authorization) && filled($authorization) ? $authorization : null;
    }

    public function lookbackHours(): int
    {
        return max(1, (int) data_get($this->settings, 'lookback_hours', 24));
    }

    public function requestLimit(): int
    {
        return max(1, min(100, (int) data_get($this->settings, 'limit', 100)));
    }

    public function telegramReference(): int|string
    {
        return filled($this->username) ? '@'.$this->username : (int) $this->telegram_peer_id;
    }

    /** @return BelongsToMany<SourceGroup, $this> */
    public function sourceGroups(): BelongsToMany
    {
        return $this->belongsToMany(SourceGroup::class, 'source_group_source');
    }

    /** @return BelongsToMany<TelegramAccount, $this> */
    public function telegramAccounts(): BelongsToMany
    {
        return $this->belongsToMany(TelegramAccount::class)
            ->withPivot(['access_status', 'last_checked_at', 'last_error'])
            ->withTimestamps();
    }

    /** @return BelongsTo<TelegramAccount, $this> */
    public function collectorTelegramAccount(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'collector_telegram_account_id');
    }

    /** @return BelongsTo<TelegramAccount, $this> */
    public function preferredCollectorTelegramAccount(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'preferred_collector_telegram_account_id');
    }

    public function hasAvailableAccessFor(int $telegramAccountId): bool
    {
        if (! $this->relationLoaded('telegramAccounts')) {
            return $this->telegramAccounts()
                ->whereKey($telegramAccountId)
                ->wherePivot('access_status', TelegramSourceAccessStatus::Available->value)
                ->exists();
        }

        $account = $this->telegramAccounts->firstWhere('id', $telegramAccountId);
        $pivot = $account?->relationLoaded('pivot') ? $account->getRelation('pivot') : null;

        return $pivot instanceof Pivot
            && $pivot->getAttribute('access_status') === TelegramSourceAccessStatus::Available->value;
    }

    public function collectorStatus(): string
    {
        if ($this->collector_telegram_account_id === null) {
            return 'unavailable';
        }

        if ($this->preferred_collector_telegram_account_id === null) {
            return 'automatic';
        }

        return $this->collector_telegram_account_id === $this->preferred_collector_telegram_account_id
            ? 'preferred'
            : 'fallback';
    }

    public function collectorLastError(): ?string
    {
        $targetAccountId = $this->preferred_collector_telegram_account_id
            ?? $this->collector_telegram_account_id;

        if ($targetAccountId !== null) {
            return $this->accessErrorFor($targetAccountId);
        }

        return $this->telegramAccounts
            ->map(fn (TelegramAccount $account): ?string => $this->accessErrorFor($account->id))
            ->filter()
            ->first();
    }

    public function shouldRetryPreferredCollectorSubscription(): bool
    {
        if (
            blank($this->username)
            || $this->preferred_collector_telegram_account_id === null
            || $this->hasAvailableAccessFor($this->preferred_collector_telegram_account_id)
        ) {
            return false;
        }

        $account = $this->telegramAccounts->firstWhere(
            'id',
            $this->preferred_collector_telegram_account_id,
        );
        $pivot = $account?->relationLoaded('pivot') ? $account->getRelation('pivot') : null;
        $lastCheckedAt = $pivot instanceof Pivot ? $pivot->getAttribute('last_checked_at') : null;

        return blank($lastCheckedAt)
            || Carbon::parse($lastCheckedAt)->lessThanOrEqualTo(now()->subMinutes(5));
    }

    /** @return HasMany<SourcePost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(SourcePost::class);
    }

    /** @return HasMany<SourceMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SourceMessage::class);
    }

    /**
     * @param  Builder<Source>  $query
     * @return Builder<Source>
     */
    public function scopeWithLastDayStatistics(Builder $query): Builder
    {
        $recentPosts = static fn (Builder $query): Builder => $query
            ->where('posted_at', '>=', now()->subDay())
            ->where('status', 'active')
            ->whereNull('deleted_at');

        return $query
            ->withCount(['posts as posts_last_day_count' => $recentPosts])
            ->withSum(['posts as views_last_day' => $recentPosts], 'views')
            ->withSum(['posts as forwards_last_day' => $recentPosts], 'forwards')
            ->withSum(['posts as reactions_last_day' => $recentPosts], 'reactions')
            ->withSum(['posts as comments_last_day' => $recentPosts], 'comments')
            ->withCasts([
                'posts_last_day_count' => 'integer',
                'views_last_day' => 'integer',
                'forwards_last_day' => 'integer',
                'reactions_last_day' => 'integer',
                'comments_last_day' => 'integer',
            ]);
    }

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'weight' => 'decimal:2',
            'is_active' => 'boolean',
            'last_event_at' => 'datetime',
            'last_backfilled_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'settings' => 'array',
            'credentials' => 'encrypted:array',
            'metadata' => 'array',
            'last_sync_summary' => 'array',
        ];
    }

    private function accessErrorFor(int $telegramAccountId): ?string
    {
        $account = $this->telegramAccounts->firstWhere('id', $telegramAccountId);
        $pivot = $account?->relationLoaded('pivot') ? $account->getRelation('pivot') : null;
        $error = $pivot instanceof Pivot ? $pivot->getAttribute('last_error') : null;

        return is_string($error) && filled($error) ? $error : null;
    }
}
