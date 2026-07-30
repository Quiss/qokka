<?php

namespace Tests\Unit;

use App\Exceptions\TelegramApiServerException;
use App\Services\TelegramApiServer;
use App\Services\TelegramApiServerClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramApiServerClientTest extends TestCase
{
    public function test_it_maps_channel_message_requests_to_the_http_api(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'http://telegram-api:9503/api/session-uuid/getMessages' => Http::response([
                'success' => true,
                'response' => [
                    'messages' => [
                        ['_' => 'message', 'id' => 44, 'message' => 'ok'],
                    ],
                ],
                'errors' => [],
            ]),
        ]);
        $client = new TelegramApiServerClient(
            app(TelegramApiServer::class),
            'session-uuid',
        );

        $message = $client->getChannelMessage(-100123, 44);

        $this->assertSame(44, $message['id']);
        Http::assertSent(
            fn ($request): bool => $request->url() === 'http://telegram-api:9503/api/session-uuid/getMessages'
                && $request['peer'] === -100123
                && $request['id'] === [44],
        );
    }

    public function test_it_exposes_rpc_codes_from_api_errors(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'http://telegram-api:9503/api/session-uuid/channels.joinChannel' => Http::response([
                'success' => false,
                'response' => null,
                'errors' => [[
                    'exception' => 'danog\\MadelineProto\\RPCErrorException',
                    'message' => 'USER_ALREADY_PARTICIPANT',
                    'code' => 400,
                ]],
            ], 400),
        ]);
        $client = new TelegramApiServerClient(
            app(TelegramApiServer::class),
            'session-uuid',
        );

        try {
            $client->joinChannel('@channel');
            $this->fail('Expected TelegramApiServerException was not thrown.');
        } catch (TelegramApiServerException $exception) {
            $this->assertSame('USER_ALREADY_PARTICIPANT', $exception->rpc);
            $this->assertSame('USER_ALREADY_PARTICIPANT', $exception->getMessage());
            $this->assertSame(
                'danog\\MadelineProto\\RPCErrorException',
                $exception->remoteException,
            );
        }
    }

    public function test_it_refreshes_dialog_peers_and_retries_a_missing_numeric_peer_once(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'http://telegram-api:9503/api/session-uuid/getMessages' => Http::sequence()
                ->push([
                    'success' => false,
                    'response' => null,
                    'errors' => [[
                        'exception' => 'danog\\MadelineProto\\PeerNotInDbException',
                        'message' => 'This peer is not present in the internal peer database',
                        'code' => 0,
                    ]],
                ], 500)
                ->push([
                    'success' => true,
                    'response' => [
                        'messages' => [
                            ['_' => 'message', 'id' => 44, 'message' => 'ok'],
                        ],
                    ],
                    'errors' => [],
                ]),
            'http://telegram-api:9503/api/session-uuid/getDialogIds' => Http::response([
                'success' => true,
                'response' => [-100123],
                'errors' => [],
            ]),
        ]);
        $client = new TelegramApiServerClient(
            app(TelegramApiServer::class),
            'session-uuid',
        );

        $message = $client->getChannelMessage(-100123, 44);

        $this->assertSame(44, $message['id']);
        $this->assertCount(2, Http::recorded(
            fn ($request): bool => $request->url()
                === 'http://telegram-api:9503/api/session-uuid/getMessages',
        ));
        Http::assertSent(
            fn ($request): bool => $request->url()
                === 'http://telegram-api:9503/api/session-uuid/getDialogIds',
        );
    }

    public function test_media_downloads_stay_inside_telegram_api_server(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'http://telegram-api:9503/api/session-uuid/getMedia' => Http::response(
                'media-bytes',
                200,
                ['Content-Type' => 'application/octet-stream'],
            ),
        ]);
        $path = sys_get_temp_dir().'/telegram-api-client-'.bin2hex(random_bytes(8)).'.part';
        $client = new TelegramApiServerClient(
            app(TelegramApiServer::class),
            'session-uuid',
        );

        try {
            $this->assertSame(
                $path,
                $client->downloadMessageToFile(-100123, 44, $path, false),
            );
            Http::assertSent(
                fn ($request): bool => $request->url() === 'http://telegram-api:9503/api/session-uuid/getMedia'
                    && $request['peer'] === -100123
                    && $request['id'] === 44,
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_session_list_uses_the_system_endpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'http://telegram-api:9503/system/getSessionList' => Http::response([
                'success' => true,
                'response' => [
                    'sessions' => [
                        'session-uuid' => [
                            'session' => 'session-uuid',
                            'file' => 'sessions/session-uuid.madeline',
                            'status' => 'LOGGED_IN',
                        ],
                    ],
                ],
                'errors' => [],
            ]),
        ]);

        $sessions = app(TelegramApiServer::class)->sessions();

        $this->assertSame('LOGGED_IN', $sessions['session-uuid']['status']);
    }
}
