<?php

namespace App\Services;

use App\Models\TelegramAccount;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use LogicException;

class TelegramMediaDownloadConcurrency
{
    /**
     * @param  Closure(): void  $operation
     */
    public function run(TelegramAccount $account, Closure $operation): bool
    {
        $lock = $this->lockProvider()->lock(
            $this->key($account),
            max(1, (int) config('services.telegram.media_lock_seconds', 420)),
        );

        if (! $lock->get()) {
            return false;
        }

        try {
            $operation();
        } finally {
            $lock->release();
        }

        return true;
    }

    public function isIdle(TelegramAccount $account): bool
    {
        $lock = $this->lockProvider()->lock($this->key($account), 1);

        if (! $lock->get()) {
            return false;
        }

        $lock->release();

        return true;
    }

    private function key(TelegramAccount $account): string
    {
        return 'telegram:media-download:'.$account->uuid;
    }

    private function storeName(): string
    {
        return (string) config('services.telegram.coordination_cache_store', 'redis');
    }

    private function lockProvider(): LockProvider
    {
        $store = Cache::store($this->storeName())->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException(
                "Cache store [{$this->storeName()}] does not support atomic locks.",
            );
        }

        return $store;
    }
}
