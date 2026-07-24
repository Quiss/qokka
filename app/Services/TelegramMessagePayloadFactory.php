<?php

namespace App\Services;

use App\Models\SourceChannel;
use App\Models\TelegramAccount;

class TelegramMessagePayloadFactory
{
    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function fromRawMessage(
        TelegramAccount $telegramAccount,
        SourceChannel $sourceChannel,
        array $message,
    ): array {
        return [
            'telegram_account_uuid' => $telegramAccount->uuid,
            'event_type' => isset($message['edit_date']) ? 'edit' : 'message',
            'peer_id' => $sourceChannel->telegram_peer_id,
            'message_id' => (int) $message['id'],
            'grouped_id' => isset($message['grouped_id']) ? (string) $message['grouped_id'] : null,
            'posted_at' => date(DATE_ATOM, (int) $message['date']),
            'text' => $message['message'] ?? null,
            'entities' => is_array($message['entities'] ?? null) ? $message['entities'] : [],
            'metrics' => $this->metrics($message),
            'media' => $this->media($message),
            'raw' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function metrics(array $message): array
    {
        $reactionBreakdown = [];
        $reactionTotal = 0;
        $results = is_array($message['reactions']['results'] ?? null)
            ? $message['reactions']['results']
            : [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $count = max(0, (int) ($result['count'] ?? 0));
            $reaction = is_array($result['reaction'] ?? null) ? $result['reaction'] : [];
            $key = match ($reaction['_'] ?? null) {
                'reactionEmoji' => (string) ($reaction['emoticon'] ?? 'unknown'),
                'reactionCustomEmoji' => 'custom:'.($reaction['document_id'] ?? 'unknown'),
                'reactionPaid' => 'paid',
                default => 'unknown',
            };
            $reactionBreakdown[$key] = ($reactionBreakdown[$key] ?? 0) + $count;
            $reactionTotal += $count;
        }

        return [
            'views' => max(0, (int) ($message['views'] ?? 0)),
            'forwards' => max(0, (int) ($message['forwards'] ?? 0)),
            'reactions' => $reactionTotal,
            'comments' => max(0, (int) ($message['replies']['replies'] ?? 0)),
            'reaction_breakdown' => $reactionBreakdown,
            'synced_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    private function media(array $message): array
    {
        $media = is_array($message['media'] ?? null) ? $message['media'] : [];

        if (($media['_'] ?? null) === 'messageMediaPhoto' && is_array($media['photo'] ?? null)) {
            $photo = $media['photo'];
            $photoSizes = is_array($photo['sizes'] ?? null) ? $photo['sizes'] : [];
            $largest = collect($photoSizes)
                ->filter(fn (mixed $size): bool => is_array($size))
                ->sortByDesc(fn (array $size): int => (int) ($size['size'] ?? 0))
                ->first();

            return [[
                'type' => 'photo',
                'external_id' => 'photo:'.($photo['id'] ?? $message['id']),
                'mime_type' => 'image/jpeg',
                'size_bytes' => is_array($largest) ? ($largest['size'] ?? null) : null,
                'metadata' => [
                    'telegram_media_type' => 'photo',
                    'file_name' => 'photo.jpg',
                ],
            ]];
        }

        if (($media['_'] ?? null) !== 'messageMediaDocument' || ! is_array($media['document'] ?? null)) {
            return [];
        }

        $document = $media['document'];
        $documentAttributes = is_array($document['attributes'] ?? null) ? $document['attributes'] : [];
        $attributes = collect($documentAttributes)->filter(fn (mixed $attribute): bool => is_array($attribute));
        $isVideo = $attributes->contains(fn (array $attribute): bool => ($attribute['_'] ?? null) === 'documentAttributeVideo');
        $isAnimation = $attributes->contains(fn (array $attribute): bool => ($attribute['_'] ?? null) === 'documentAttributeAnimated');
        $isPhoto = str_starts_with((string) ($document['mime_type'] ?? ''), 'image/');
        $fileNameAttribute = $attributes
            ->first(fn (array $attribute): bool => ($attribute['_'] ?? null) === 'documentAttributeFilename');
        $fileName = is_array($fileNameAttribute) ? ($fileNameAttribute['file_name'] ?? null) : null;
        $documentThumbnails = is_array($document['thumbs'] ?? null) ? $document['thumbs'] : [];
        $thumbnail = collect($documentThumbnails)
            ->filter(fn (mixed $thumb): bool => is_array($thumb) && filled($thumb['type'] ?? null))
            ->sortByDesc(fn (array $thumb): int => (int) ($thumb['size'] ?? 0))
            ->first();

        return [[
            'type' => $isAnimation ? 'animation' : ($isVideo ? 'video' : ($isPhoto ? 'photo' : 'document')),
            'external_id' => 'document:'.($document['id'] ?? $message['id']),
            'mime_type' => $document['mime_type'] ?? null,
            'size_bytes' => $document['size'] ?? null,
            'metadata' => array_filter([
                'telegram_media_type' => 'document',
                'file_name' => $fileName,
                'thumbnail_type' => is_array($thumbnail) ? $thumbnail['type'] : null,
            ], fn (mixed $value): bool => $value !== null),
        ]];
    }
}
