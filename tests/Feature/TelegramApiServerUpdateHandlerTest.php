<?php

namespace Tests\Feature;

use App\Jobs\IngestTelegramUpdateJob;
use App\Models\SourceChannel;
use App\Models\TelegramAccount;
use App\Services\TelegramApiServerUpdateHandler;
use App\TelegramAccountStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramApiServerUpdateHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_assigned_channel_messages_from_the_websocket_envelope(): void
    {
        Queue::fake();
        $account = TelegramAccount::factory()->create();
        $channelId = 123456;
        $peerId = -(1_000_000_000_000 + $channelId);
        SourceChannel::factory()->create([
            'collector_telegram_account_id' => $account->id,
            'telegram_peer_id' => $peerId,
        ]);

        app(TelegramApiServerUpdateHandler::class)->handle([
            'jsonrpc' => '2.0',
            'result' => [
                'session' => $account->uuid,
                'update' => [
                    '_' => 'updateNewChannelMessage',
                    'message' => [
                        '_' => 'message',
                        'id' => 77,
                        'peer_id' => ['_' => 'peerChannel', 'channel_id' => $channelId],
                        'date' => now()->timestamp,
                        'message' => 'Новое сообщение',
                        'media' => [
                            '_' => 'messageMediaDocument',
                            'document' => [
                                '_' => 'document',
                                'id' => 991,
                                'mime_type' => 'video/mp4',
                                'size' => 1200,
                                'attributes' => [
                                    ['_' => 'documentAttributeAnimated'],
                                    ['_' => 'documentAttributeFilename', 'file_name' => 'animation.mp4'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        Queue::assertPushedOn(
            'ingest',
            IngestTelegramUpdateJob::class,
            function (IngestTelegramUpdateJob $job): bool {
                $payload = $job->payload;

                return $payload['event_type'] === 'message'
                    && $payload['message_id'] === 77
                    && $payload['media'][0]['type'] === 'animation';
            },
        );
        $this->assertSame(TelegramAccountStatus::Connected, $account->fresh()->status);
        $this->assertNotNull($account->fresh()->last_seen_at);
    }

    public function test_it_ignores_messages_from_unassigned_channels(): void
    {
        Queue::fake();
        $account = TelegramAccount::factory()->create();

        app(TelegramApiServerUpdateHandler::class)->handle([
            'result' => [
                'session' => $account->uuid,
                'update' => [
                    '_' => 'updateNewChannelMessage',
                    'message' => [
                        '_' => 'message',
                        'id' => 10,
                        'peer_id' => ['_' => 'peerChannel', 'channel_id' => 999],
                        'date' => now()->timestamp,
                    ],
                ],
            ],
        ]);

        Queue::assertNothingPushed();
    }

    public function test_it_dispatches_delete_and_metric_updates(): void
    {
        Queue::fake();
        $account = TelegramAccount::factory()->create();
        $channelId = 321;
        SourceChannel::factory()->create([
            'collector_telegram_account_id' => $account->id,
            'telegram_peer_id' => -(1_000_000_000_000 + $channelId),
        ]);
        $handler = app(TelegramApiServerUpdateHandler::class);

        $handler->handle([
            'result' => [
                'session' => $account->uuid,
                'update' => [
                    '_' => 'updateDeleteChannelMessages',
                    'channel_id' => $channelId,
                    'messages' => [4, 5],
                ],
            ],
        ]);
        $handler->handle([
            'result' => [
                'session' => $account->uuid,
                'update' => [
                    '_' => 'updateChannelMessageViews',
                    'channel_id' => $channelId,
                    'id' => 5,
                    'views' => 123,
                ],
            ],
        ]);

        Queue::assertPushed(IngestTelegramUpdateJob::class, 3);
    }
}
