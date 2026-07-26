<?php

namespace App\Services;

use App\Contracts\OperationsNotifier;
use App\OperationsNotificationTopic;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class TelegramOperationsNotifier implements OperationsNotifier
{
    private const int MaxTitleLength = 600;

    private const int MaxDetailLength = 700;

    private const int MaxDetails = 4;

    public function send(
        OperationsNotificationTopic $topic,
        string $title,
        array $details,
        string $url,
    ): void {
        $token = (string) config('services.telegram.bot_token');
        $chatId = (string) config('services.telegram.operations.chat_id');
        $topicId = (int) config('services.telegram.operations.topics.'.$topic->configKey());
        $baseUrl = rtrim((string) config('services.telegram.bot_api_url', 'https://api.telegram.org'), '/');

        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        if ($chatId === '') {
            throw new RuntimeException('TELEGRAM_OPERATIONS_CHAT_ID is not configured.');
        }

        if ($topicId <= 0) {
            throw new RuntimeException("Telegram operations topic [{$topic->value}] is not configured.");
        }

        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || ! Str::startsWith($url, ['http://', 'https://'])
        ) {
            throw new RuntimeException('An operations notification requires a valid HTTP URL.');
        }

        $response = $this->client($baseUrl, $token)
            ->post('/sendMessage', [
                'chat_id' => $chatId,
                'message_thread_id' => $topicId,
                'text' => $this->formatText($title, $details),
                'parse_mode' => 'HTML',
                'link_preview_options' => ['is_disabled' => true],
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            ['text' => 'Открыть', 'url' => $url],
                        ],
                    ],
                ],
            ])
            ->throw();

        if ($response->json('ok') !== true) {
            throw new RuntimeException('Telegram returned an invalid operations notification response.');
        }
    }

    private function client(string $baseUrl, string $token): PendingRequest
    {
        return Http::baseUrl($baseUrl.'/bot'.$token)
            ->asJson()
            ->acceptJson()
            ->connectTimeout((int) config('services.telegram.operations.connect_timeout', 3))
            ->timeout((int) config('services.telegram.operations.timeout', 10));
    }

    /** @param list<string> $details */
    private function formatText(string $title, array $details): string
    {
        $escapedTitle = $this->escapeLine($title, self::MaxTitleLength);

        if ($escapedTitle === '') {
            throw new RuntimeException('An operations notification requires a title.');
        }

        $lines = ["<b>{$escapedTitle}</b>"];

        foreach (array_slice($details, 0, self::MaxDetails) as $detail) {
            $escapedDetail = $this->escapeLine($detail, self::MaxDetailLength);

            if ($escapedDetail !== '') {
                $lines[] = $escapedDetail;
            }
        }

        return implode("\n", $lines);
    }

    private function escapeLine(string $value, int $maxEncodedLength): string
    {
        $value = Str::squish($value);

        if ($value === '') {
            return '';
        }

        $escaped = e($value);

        while (Str::length($escaped) > $maxEncodedLength && Str::length($value) > 1) {
            $value = Str::substr($value, 0, max(1, Str::length($value) - 50));
            $escaped = e(rtrim($value).'…');
        }

        return $escaped;
    }
}
