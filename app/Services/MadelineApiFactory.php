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

        $api = new API($sessionDirectory, $settings);

        if ($api->isIpc()) {
            unset($api);
            gc_collect_cycles();

            throw new MadelineOwnerUnavailableException(
                "Сессия {$account->uuid} уже принадлежит другому процессу. "
                .'Остановите все процессы MadelineProto и запустите telegram:listen заново.',
            );
        }

        return $api;
    }
}
