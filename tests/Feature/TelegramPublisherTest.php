<?php

namespace Tests\Feature;

use App\Models\ContentPlan;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\StoryCandidate;
use App\Services\TelegramPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TelegramPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_post_is_sent_to_configured_destination(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.bot_api_url' => 'https://tgprx.orangepanda.ru/',
        ]);
        Http::fake([
            'https://tgprx.orangepanda.ru/bottest-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 321],
            ]),
        ]);
        $publication = Publication::factory()->create();
        $destination = Destination::factory()->create(['publication_id' => $publication->id, 'external_id' => '@poka_trend']);
        $plan = ContentPlan::factory()->create(['publication_id' => $publication->id]);
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'text' => 'Готовый пост',
        ]);
        $delivery = Delivery::factory()->create(['planned_post_id' => $post->id, 'destination_id' => $destination->id]);

        $result = app(TelegramPublisher::class)->publish($delivery);

        $this->assertSame(['321'], $result['message_ids']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tgprx.orangepanda.ru/bottest-token/sendMessage'
            && $request['chat_id'] === '@poka_trend'
            && $request['text'] === 'Готовый пост');
    }

    public function test_destination_validation_identifies_the_configured_admin_bot(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake([
            'https://api.telegram.org/bottest-token/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 42, 'username' => 'channelbot_publisher'],
            ]),
            'https://api.telegram.org/bottest-token/getChat' => Http::response([
                'ok' => true,
                'result' => ['id' => -100123, 'title' => 'Про Питер'],
            ]),
            'https://api.telegram.org/bottest-token/getChatMember' => Http::response([
                'ok' => true,
                'result' => ['status' => 'administrator'],
            ]),
        ]);
        $destination = Destination::factory()->create(['external_id' => '@pro_piter']);

        $result = app(TelegramPublisher::class)->validateDestination($destination);

        $this->assertTrue($result['ok']);
        $this->assertSame('channelbot_publisher', $result['details']['bot']['username']);
        $this->assertSame('administrator', $result['details']['membership']['status']);
    }

    public function test_destination_validation_rejects_a_bot_without_admin_access(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake([
            'https://api.telegram.org/bottest-token/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 42, 'username' => 'channelbot_publisher'],
            ]),
            'https://api.telegram.org/bottest-token/getChat' => Http::response([
                'ok' => true,
                'result' => ['id' => -100123, 'title' => 'Про Питер'],
            ]),
            'https://api.telegram.org/bottest-token/getChatMember' => Http::response([
                'ok' => true,
                'result' => ['status' => 'member'],
            ]),
        ]);
        $destination = Destination::factory()->create(['external_id' => '@pro_piter']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('должен быть администратором');

        app(TelegramPublisher::class)->validateDestination($destination);
    }
}
