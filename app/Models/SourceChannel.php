<?php

namespace App\Models;

use Database\Factories\SourceChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $collector_telegram_account_id
 * @property int|null $telegram_peer_id
 * @property string|null $username
 * @property string $title
 * @property numeric-string $weight
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property int $posts_last_day_count
 * @property int $views_last_day
 * @property int $forwards_last_day
 * @property int $reactions_last_day
 * @property int $comments_last_day
 * @property-read Collection<int, SourceGroup> $sourceGroups
 * @property-read Collection<int, TelegramAccount> $telegramAccounts
 * @property-read TelegramAccount|null $collectorTelegramAccount
 */
#[Fillable(['collector_telegram_account_id', 'telegram_peer_id', 'username', 'title', 'weight', 'is_active', 'last_event_at', 'last_backfilled_at', 'metadata'])]
class SourceChannel extends Model
{
    /** @use HasFactory<SourceChannelFactory> */
    use HasFactory;

    protected $attributes = ['weight' => 1, 'is_active' => true];

    protected static function booted(): void
    {
        static::creating(function (SourceChannel $channel): void {
            $channel->username = filled($channel->username)
                ? self::normalizeUsername((string) $channel->username)
                : null;
            $channel->title = filled($channel->title)
                ? $channel->title
                : ($channel->username ? '@'.$channel->username : (string) $channel->telegram_peer_id);
        });

        static::updating(function (SourceChannel $channel): void {
            if ($channel->isDirty('username') && filled($channel->username)) {
                $channel->username = self::normalizeUsername((string) $channel->username);
            }
        });
    }

    public static function normalizeUsername(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('#^https?://t\.me/#i', '', $value) ?? $value;

        return ltrim(Str::before($value, '/'), '@');
    }

    public function telegramReference(): int|string
    {
        return filled($this->username) ? '@'.$this->username : (int) $this->telegram_peer_id;
    }

    /** @return BelongsToMany<SourceGroup, $this> */
    public function sourceGroups(): BelongsToMany
    {
        return $this->belongsToMany(SourceGroup::class);
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

    /** @param Builder<SourceChannel> $query
     * @return Builder<SourceChannel>
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
            'weight' => 'decimal:2',
            'is_active' => 'boolean',
            'last_event_at' => 'datetime',
            'last_backfilled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
