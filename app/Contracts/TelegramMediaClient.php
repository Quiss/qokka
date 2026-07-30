<?php

namespace App\Contracts;

interface TelegramMediaClient
{
    public function downloadMessageToFile(
        int|string $peer,
        int $messageId,
        string $path,
        bool $previewOnly,
    ): string;
}
