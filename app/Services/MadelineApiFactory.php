<?php

namespace App\Services;

use App\Exceptions\MadelineOwnerUnavailableException;
use App\Models\TelegramAccount;
use danog\MadelineProto\API;
use Illuminate\Support\Facades\Log;

class MadelineApiFactory
{
    public function __construct(
        private readonly MadelineSettingsFactory $settingsFactory,
        private readonly MadelineSessionDatabaseSettingsSynchronizer $settingsSynchronizer,
        private readonly MadelineOwnerLease $ownerLease,
    ) {}

    public function makeOwner(TelegramAccount $account): API
    {
        $sessionDirectory = $this->settingsFactory->sessionPath($account);
        $settings = $this->settingsFactory->make($account);

        if ($this->settingsSynchronizer->synchronize($sessionDirectory, $settings)) {
            Log::notice('Обновлены сохранённые настройки базы данных MadelineProto.', [
                'telegram_account_id' => $account->getKey(),
                'telegram_account_uuid' => $account->uuid,
            ]);
        }

        return new API($sessionDirectory, $settings);
    }

    public function makeIpcClient(TelegramAccount $account): API
    {
        if (! $this->ownerLease->isFresh($account->uuid)) {
            throw new MadelineOwnerUnavailableException(
                "MadelineProto owner lease is unavailable for Telegram account {$account->uuid}.",
            );
        }

        $api = new API($this->settingsFactory->sessionPath($account));

        if (! $api->isIpc() || $api->isIpcWorker()) {
            unset($api);
            gc_collect_cycles();

            throw new MadelineOwnerUnavailableException(
                "MadelineProto IPC client connected to an invalid owner for Telegram account {$account->uuid}.",
            );
        }

        return $api;
    }
}
