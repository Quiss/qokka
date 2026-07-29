<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

class MadelineOwnerLease
{
    private readonly string $ownerToken;

    public function __construct(private readonly CacheFactory $cache)
    {
        $this->ownerToken = (string) Str::uuid();
    }

    public function heartbeat(string $telegramAccountUuid): void
    {
        $this->store()->put(
            $this->key($telegramAccountUuid),
            [
                'owner_token' => $this->ownerToken,
                'process_id' => getmypid(),
                'hostname' => gethostname() ?: null,
                'heartbeat_at' => now()->getTimestamp(),
            ],
            $this->ttl(),
        );
    }

    public function isFresh(string $telegramAccountUuid): bool
    {
        $lease = $this->lease($telegramAccountUuid);

        return $lease !== null
            && $lease['heartbeat_at'] >= now()->subSeconds($this->ttl())->getTimestamp();
    }

    /**
     * @return array{owner_token: string, process_id: int|false, hostname: string|null, heartbeat_at: int}|null
     */
    public function lease(string $telegramAccountUuid): ?array
    {
        $lease = $this->store()->get($this->key($telegramAccountUuid));

        if (
            ! is_array($lease)
            || ! is_string($lease['owner_token'] ?? null)
            || (! is_int($lease['process_id'] ?? null) && ($lease['process_id'] ?? null) !== false)
            || (! is_string($lease['hostname'] ?? null) && ($lease['hostname'] ?? null) !== null)
            || ! is_int($lease['heartbeat_at'] ?? null)
        ) {
            return null;
        }

        return [
            'owner_token' => $lease['owner_token'],
            'process_id' => $lease['process_id'],
            'hostname' => $lease['hostname'],
            'heartbeat_at' => $lease['heartbeat_at'],
        ];
    }

    public function release(string $telegramAccountUuid): void
    {
        $lease = $this->lease($telegramAccountUuid);

        if (($lease['owner_token'] ?? null) === $this->ownerToken) {
            $this->store()->forget($this->key($telegramAccountUuid));
        }
    }

    private function store(): Repository
    {
        return $this->cache->store(
            (string) config('services.telegram.coordination_cache_store', 'redis'),
        );
    }

    private function key(string $telegramAccountUuid): string
    {
        return 'telegram:madeline-owner:'.$telegramAccountUuid;
    }

    private function ttl(): int
    {
        return max(1, (int) config('services.telegram.owner_lease_ttl_seconds', 45));
    }
}
