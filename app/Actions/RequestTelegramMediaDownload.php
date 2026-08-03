<?php

namespace App\Actions;

use App\Models\MediaAsset;
use App\Models\TelegramOwnerCommand;
use App\Services\TelegramMediaDownloadAccountResolver;
use App\Services\TelegramOwnerCommandDispatcher;
use App\TelegramOwnerCommandType;
use Illuminate\Support\Arr;
use RuntimeException;

class RequestTelegramMediaDownload
{
    public function __construct(
        private readonly TelegramMediaDownloadAccountResolver $accountResolver,
        private readonly TelegramOwnerCommandDispatcher $commandDispatcher,
    ) {}

    public function handle(MediaAsset $mediaAsset, bool $previewOnly = false): TelegramOwnerCommand
    {
        $origin = $mediaAsset->originMediaAsset ?? $mediaAsset;
        $origin->loadMissing(
            'sourceMessage.telegramAccount',
            'sourceMessage.source.collectorTelegramAccount',
            'sourceMessage.source.telegramAccounts',
        );
        $sourceMessage = $origin->sourceMessage;

        if ($sourceMessage === null) {
            throw new RuntimeException("Медиа {$origin->id} не связано с исходным Telegram-сообщением.");
        }

        $telegramAccount = $this->accountResolver->resolve($sourceMessage);

        if ($telegramAccount === null) {
            throw new RuntimeException('Для медиа не найден Telegram-аккаунт с доступом к источнику.');
        }

        $errorKey = $previewOnly ? 'preview_download_error' : 'download_error';
        $origin->update([
            'failed_at' => $previewOnly ? $origin->failed_at : null,
            'preview_failed_at' => $previewOnly ? null : $origin->preview_failed_at,
            'metadata' => Arr::except(
                is_array($origin->metadata) ? $origin->metadata : [],
                [$errorKey],
            ),
        ]);

        return $this->commandDispatcher->dispatch(
            $telegramAccount,
            $previewOnly
                ? TelegramOwnerCommandType::DownloadMediaPreview
                : TelegramOwnerCommandType::DownloadMedia,
            ['media_asset_id' => $origin->id],
            'media:'.$origin->id.':'.($previewOnly ? 'preview' : 'full'),
            priority: $previewOnly ? 50 : 100,
            maxAttempts: 3,
        );
    }
}
