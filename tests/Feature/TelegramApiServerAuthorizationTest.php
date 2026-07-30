<?php

namespace Tests\Feature;

use App\Models\TelegramAccount;
use App\TelegramAccountStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramApiServerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_can_be_authorized_through_the_http_api(): void
    {
        Http::preventStrayRequests();
        $account = TelegramAccount::factory()->create([
            'name' => 'collector',
            'status' => TelegramAccountStatus::Error,
            'telegram_user_id' => null,
            'username' => null,
            'phone_hint' => null,
        ]);
        $session = $account->uuid;
        $sessionResponse = fn (string $status): array => [
            'success' => true,
            'response' => [
                'sessions' => [
                    $session => [
                        'session' => $session,
                        'file' => "sessions/{$session}.madeline",
                        'status' => $status,
                    ],
                ],
            ],
            'errors' => [],
        ];
        Http::fake([
            'http://telegram-api:9503/system/getSessionList' => Http::sequence()
                ->push([
                    'success' => true,
                    'response' => ['sessions' => []],
                    'errors' => [],
                ])
                ->push($sessionResponse('NOT_LOGGED_IN'))
                ->push($sessionResponse('LOGGED_IN'))
                ->push($sessionResponse('LOGGED_IN')),
            'http://telegram-api:9503/system/addSession' => Http::response([
                'success' => true,
                'response' => ['sessions' => []],
                'errors' => [],
            ]),
            "http://telegram-api:9503/api/{$session}/phoneLogin" => Http::response([
                'success' => true,
                'response' => ['_' => 'auth.sentCode'],
                'errors' => [],
            ]),
            "http://telegram-api:9503/api/{$session}/completePhoneLogin" => Http::response([
                'success' => true,
                'response' => ['_' => 'auth.authorization'],
                'errors' => [],
            ]),
            "http://telegram-api:9503/api/{$session}/serialize" => Http::response([
                'success' => true,
                'response' => null,
                'errors' => [],
            ]),
            "http://telegram-api:9503/api/{$session}/getSelf" => Http::response([
                'success' => true,
                'response' => [
                    'id' => 123456,
                    'username' => 'collector_user',
                    'phone' => '79991234567',
                ],
                'errors' => [],
            ]),
        ]);

        $this->artisan('telegram:account:authorize collector')
            ->expectsQuestion('Номер телефона в международном формате (+...)', '+79991234567')
            ->expectsQuestion('Код подтверждения из Telegram', '12345')
            ->expectsOutputToContain('Аккаунт «collector» подключён')
            ->assertSuccessful();

        $account->refresh();
        $this->assertSame(TelegramAccountStatus::Authorized, $account->status);
        $this->assertSame(123456, $account->telegram_user_id);
        $this->assertSame('collector_user', $account->username);
        $this->assertSame('***4567', $account->phone_hint);
        Http::assertSent(
            fn ($request): bool => $request->url() === "http://telegram-api:9503/api/{$session}/phoneLogin"
                && $request['number'] === '+79991234567',
        );
    }
}
