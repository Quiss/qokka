<?php

namespace App\Services;

use App\Contracts\Publisher;
use App\DestinationPlatform;
use App\MediaType;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\MediaAsset;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class TelegramPublisher implements Publisher
{
    public function __construct(private readonly TelegramMessageFormatter $formatter) {}

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
        $caption = $this->formatter->length($text) <= $captionLimit
            ? $this->formatter->toHtml($text)
            : '';

        if ($media->count() === 1) {
            $asset = $media->first();
            $field = $asset->type === MediaType::Photo ? 'photo' : 'video';
            $method = $asset->type === MediaType::Photo ? 'sendPhoto' : 'sendVideo';
            $request = $this->client()
                ->attach($field, Storage::disk($asset->disk)->get($asset->path), basename($asset->path));
            $payload = [
                'chat_id' => $delivery->destination->external_id,
                'caption' => $caption,
                'parse_mode' => $caption !== '' ? 'HTML' : null,
            ];

            if ($asset->type === MediaType::Video) {
                $payload = array_merge($payload, $this->videoPayload($asset));
                $this->attachThumbnail($request, $asset, 'thumbnail', $payload);
            }

            $response = $request
                ->post('/'.$method, array_filter($payload, fn (mixed $value): bool => $value !== null && $value !== ''))
                ->throw();
            $messageIds[] = (string) $response->json('result.message_id');
        } else {
            $request = $this->client();
            $payload = [];

            foreach ($media as $index => $asset) {
                $attachment = 'asset_'.$index;
                $request->attach($attachment, Storage::disk($asset->disk)->get($asset->path), basename($asset->path));
                $item = [
                    'type' => $asset->type === MediaType::Photo ? 'photo' : 'video',
                    'media' => 'attach://'.$attachment,
                    'caption' => $index === 0 ? $caption : '',
                    'parse_mode' => $index === 0 && $caption !== '' ? 'HTML' : null,
                ];

                if ($asset->type === MediaType::Video) {
                    $item = array_merge($item, $this->videoPayload($asset));
                    $this->attachThumbnail($request, $asset, 'thumbnail_'.$index, $item);
                }

                $payload[] = array_filter($item, fn (mixed $value): bool => $value !== null && $value !== '');
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

        foreach ($this->formatter->chunks($text) as $chunk) {
            $response = $this->client()->post('/sendMessage', [
                'chat_id' => $chatId,
                'text' => $chunk,
                'parse_mode' => 'HTML',
            ])->throw();
            $ids[] = (string) $response->json('result.message_id');
        }

        if ($ids === []) {
            throw new RuntimeException('A planned post cannot be published without text or media.');
        }

        return $ids;
    }

    /** @return array<string, int|bool> */
    private function videoPayload(MediaAsset $asset): array
    {
        $metadata = is_array($asset->metadata) ? $asset->metadata : [];
        $payload = [];

        foreach (['width', 'height', 'duration'] as $key) {
            if (is_numeric($metadata[$key] ?? null) && (int) $metadata[$key] > 0) {
                $payload[$key] = (int) $metadata[$key];
            }
        }

        if (($metadata['supports_streaming'] ?? false) === true) {
            $payload['supports_streaming'] = true;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function attachThumbnail(
        PendingRequest $request,
        MediaAsset $asset,
        string $attachment,
        array &$payload,
    ): void {
        $disk = $asset->preview_disk ?: $asset->disk;

        if (blank($asset->preview_path) || ! Storage::disk($disk)->exists($asset->preview_path)) {
            return;
        }

        try {
            if (Storage::disk($disk)->size($asset->preview_path) > 200 * 1024) {
                return;
            }

            $request->attach(
                $attachment,
                Storage::disk($disk)->get($asset->preview_path),
                basename($asset->preview_path),
            );
            $payload['thumbnail'] = 'attach://'.$attachment;
        } catch (Throwable) {
            return;
        }
    }
}
