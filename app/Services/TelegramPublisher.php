<?php

namespace App\Services;

use App\Contracts\Publisher;
use App\DestinationPlatform;
use App\MediaType;
use App\Models\Delivery;
use App\Models\Destination;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class TelegramPublisher implements Publisher
{
    public function validateDestination(Destination $destination): array
    {
        $client = $this->client();
        $bot = $client->post('/getMe')->throw()->json('result');
        $chat = $client->post('/getChat', ['chat_id' => $destination->external_id])->throw()->json('result');

        if (! is_array($bot) || ! isset($bot['id'])) {
            throw new RuntimeException('Telegram returned an invalid bot identity.');
        }

        if (! is_array($chat)) {
            throw new RuntimeException('Telegram returned an invalid destination channel.');
        }

        $membership = $client->post('/getChatMember', [
            'chat_id' => $destination->external_id,
            'user_id' => $bot['id'],
        ])->throw()->json('result');

        if (! is_array($membership)) {
            throw new RuntimeException('Telegram returned an invalid bot membership.');
        }

        $status = $membership['status'] ?? null;

        if (! in_array($status, ['administrator', 'creator'], true)) {
            $username = $bot['username'] ?? $bot['id'];

            throw new RuntimeException("Бот @{$username} должен быть администратором канала назначения.");
        }

        return [
            'ok' => true,
            'details' => [
                'bot' => $bot,
                'chat' => $chat,
                'membership' => $membership,
            ],
        ];
    }

    public function publish(Delivery $delivery): array
    {
        $delivery->loadMissing('destination', 'plannedPost.contentPlan.publication', 'plannedPost.mediaAssets');

        if ($delivery->destination->platform !== DestinationPlatform::Telegram) {
            throw new RuntimeException('Telegram publisher received a non-Telegram destination.');
        }

        $plannedPost = $delivery->plannedPost;
        $text = trim((string) $plannedPost->text);
        $selectedMedia = $plannedPost->mediaAssets
            ->whereIn('type', [MediaType::Photo, MediaType::Video])
            ->take(10)
            ->values();
        $maxBytes = (int) config('services.telegram.media_max_bytes', 50 * 1024 * 1024);
        $unavailable = $selectedMedia->first(
            fn ($asset): bool => blank($asset->path) || ! Storage::disk($asset->disk)->exists($asset->path),
        );
        $oversized = $selectedMedia->first(
            fn ($asset): bool => $asset->size_bytes !== null && $asset->size_bytes > $maxBytes,
        );

        if ($unavailable !== null) {
            throw new RuntimeException('Выбранное медиа ещё не подготовлено к публикации.');
        }

        if ($oversized !== null) {
            throw new RuntimeException('Выбранное медиа превышает лимит Telegram 50 МБ.');
        }

        $media = $selectedMedia;
        $messageIds = [];

        if ($media->isEmpty()) {
            return ['message_ids' => $this->sendText($delivery->destination->external_id, $text)];
        }

        $captionLimit = (int) $plannedPost->contentPlan->publication->media_caption_limit;
        $caption = Str::length($text) <= $captionLimit ? $text : '';

        if ($media->count() === 1) {
            $asset = $media->first();
            $field = $asset->type === MediaType::Photo ? 'photo' : 'video';
            $method = $asset->type === MediaType::Photo ? 'sendPhoto' : 'sendVideo';
            $response = $this->client()
                ->attach($field, Storage::disk($asset->disk)->get($asset->path), basename($asset->path))
                ->post('/'.$method, array_filter([
                    'chat_id' => $delivery->destination->external_id,
                    'caption' => $caption,
                ], fn ($value): bool => $value !== ''))
                ->throw();
            $messageIds[] = (string) $response->json('result.message_id');
        } else {
            $request = $this->client();
            $payload = [];

            foreach ($media as $index => $asset) {
                $attachment = 'asset_'.$index;
                $request->attach($attachment, Storage::disk($asset->disk)->get($asset->path), basename($asset->path));
                $payload[] = array_filter([
                    'type' => $asset->type === MediaType::Photo ? 'photo' : 'video',
                    'media' => 'attach://'.$attachment,
                    'caption' => $index === 0 ? $caption : '',
                ], fn ($value): bool => $value !== '');
            }

            $response = $request->post('/sendMediaGroup', [
                'chat_id' => $delivery->destination->external_id,
                'media' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ])->throw();
            $result = $response->json('result');

            if (! is_array($result)) {
                throw new RuntimeException('Telegram returned an invalid media group response.');
            }

            foreach ($result as $message) {
                if (is_array($message) && isset($message['message_id'])) {
                    $messageIds[] = (string) $message['message_id'];
                }
            }
        }

        if ($caption === '' && $text !== '') {
            $messageIds = array_merge($messageIds, $this->sendText($delivery->destination->external_id, $text));
        }

        return ['message_ids' => $messageIds];
    }

    private function client(): PendingRequest
    {
        $token = (string) config('services.telegram.bot_token');
        $baseUrl = rtrim((string) config('services.telegram.bot_api_url', 'https://api.telegram.org'), '/');

        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        return Http::baseUrl($baseUrl.'/bot'.$token)
            ->acceptJson()
            ->connectTimeout((int) config('services.telegram.bot_api_connect_timeout', 10))
            ->timeout((int) config('services.telegram.bot_api_timeout', 300));
    }

    /** @return list<string> */
    private function sendText(string $chatId, string $text): array
    {
        $ids = [];

        foreach ($this->splitText($text) as $chunk) {
            $response = $this->client()->post('/sendMessage', ['chat_id' => $chatId, 'text' => $chunk])->throw();
            $ids[] = (string) $response->json('result.message_id');
        }

        return $ids;
    }

    /** @return list<string> */
    private function splitText(string $text): array
    {
        if ($text === '') {
            throw new RuntimeException('A planned post cannot be published without text or media.');
        }

        $chunks = [];

        while ($text !== '') {
            $chunks[] = mb_substr($text, 0, 4096);
            $text = mb_substr($text, 4096);
        }

        return $chunks;
    }
}
