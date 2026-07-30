<?php

namespace Tests\Feature;

use App\Actions\IngestTelegramUpdate;
use App\Jobs\IngestTelegramUpdateJob;
use App\Models\SourceChannel;
use App\Models\TelegramAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramIngestionTest extends TestCase
{
    use RefreshDatabase;

    private TelegramAccount $telegramAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegramAccount = TelegramAccount::factory()->create();
    }

    public function test_signed_bridge_request_is_queued(): void
    {
        Queue::fake();
        config(['services.telegram.bridge_secret' => 'bridge-secret']);
        $payload = $this->payload();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $signature = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, 'bridge-secret');

        $response = $this->call('POST', '/api/internal/telegram/updates', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TELEGRAM_TIMESTAMP' => $timestamp,
            'HTTP_X_TELEGRAM_NONCE' => $nonce,
            'HTTP_X_TELEGRAM_SIGNATURE' => $signature,
        ], $body);

        $response->assertAccepted();
        Queue::assertPushed(IngestTelegramUpdateJob::class);
    }

    public function test_unsigned_bridge_request_is_rejected(): void
    {
        config(['services.telegram.bridge_secret' => 'bridge-secret']);

        $this->postJson('/api/internal/telegram/updates', $this->payload())->assertUnauthorized();
    }

    public function test_album_messages_are_grouped_and_edits_are_idempotent(): void
    {
        $channel = SourceChannel::factory()->create([
            'telegram_peer_id' => -100123,
            'collector_telegram_account_id' => $this->telegramAccount->id,
        ]);
        $action = app(IngestTelegramUpdate::class);
        $first = $action->handle($this->payload([
            'message_id' => 10,
            'grouped_id' => '777',
            'media' => [$this->photo('photo:10')],
        ]));
        $action->handle($this->payload([
            'message_id' => 11,
            'grouped_id' => '777',
            'text' => 'Вторая часть',
            'media' => [$this->photo('photo:11')],
        ]));
        $action->handle($this->payload(['event_type' => 'edit', 'message_id' => 10, 'grouped_id' => '777', 'text' => 'Исправлено']));

        $this->assertNotNull($first);
        $this->assertDatabaseCount('source_posts', 1);
        $this->assertDatabaseCount('source_messages', 2);
        $this->assertSame("Исправлено\n\nВторая часть", $first->fresh()->text);
        $this->assertSame($channel->id, $first->source_channel_id);
        $this->assertSame(1, $first->mediaAssets()->count());
        $this->assertSame(
            11,
            $first->mediaAssets()->firstOrFail()->sourceMessage?->external_message_id,
        );

        $action->handle($this->payload([
            'event_type' => 'delete',
            'message_id' => 11,
            'grouped_id' => '777',
        ]));

        $this->assertSame(0, $first->mediaAssets()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'telegram_account_uuid' => $this->telegramAccount->uuid,
            'event_type' => 'message',
            'peer_id' => -100123,
            'message_id' => 10,
            'posted_at' => now()->toIso8601String(),
            'text' => 'Важная новость',
            'metrics' => ['views' => 1000],
            'media' => [],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function photo(string $externalId): array
    {
        return [
            'type' => 'photo',
            'external_id' => $externalId,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1000,
            'metadata' => [],
        ];
    }
}
