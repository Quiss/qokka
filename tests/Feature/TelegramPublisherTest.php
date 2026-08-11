<?php

namespace Tests\Feature;

use App\MediaType;
use App\Models\ContentPlan;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\StoryCandidate;
use App\Services\TelegramPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use ReflectionProperty;
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
            'text' => "**Готовый** ++пост++ с ||деталью||\n\n> [!EXPANDABLE]\n> Подтвержденная цитата",
        ]);
        $delivery = Delivery::factory()->create(['planned_post_id' => $post->id, 'destination_id' => $destination->id]);

        $result = app(TelegramPublisher::class)->publish($delivery);

        $this->assertSame(['321'], $result['message_ids']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tgprx.orangepanda.ru/bottest-token/sendMessage'
            && $request['chat_id'] === '@poka_trend'
            && $request['text'] === "<b>Готовый</b> <u>пост</u> с <tg-spoiler>деталью</tg-spoiler>\n\n<blockquote expandable>Подтвержденная цитата</blockquote>"
            && $request['parse_mode'] === 'HTML');
    }

    public function test_video_is_sent_with_dimensions_streaming_metadata_and_preview(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('telegram/video.mp4', 'original-video');
        Storage::disk('local')->put('telegram/preview.jpg', 'preview');
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.bot_api_url' => 'https://tgprx.orangepanda.ru/',
        ]);
        Http::fake([
            'https://tgprx.orangepanda.ru/bottest-token/sendVideo' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 654],
            ]),
        ]);
        $publication = Publication::factory()->create();
        $destination = Destination::factory()->create(['publication_id' => $publication->id, 'external_id' => '@poka_trend']);
        $plan = ContentPlan::factory()->create(['publication_id' => $publication->id]);
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'text' => '**Видео дня**',
        ]);
        MediaAsset::factory()->for($post, 'mediable')->create([
            'type' => MediaType::Video,
            'path' => 'telegram/video.mp4',
            'preview_disk' => 'local',
            'preview_path' => 'telegram/preview.jpg',
            'preview_mime_type' => 'image/jpeg',
            'mime_type' => 'video/mp4',
            'metadata' => [
                'width' => 1080,
                'height' => 1920,
                'duration' => 15,
                'supports_streaming' => true,
            ],
        ]);
        $delivery = Delivery::factory()->create(['planned_post_id' => $post->id, 'destination_id' => $destination->id]);

        $result = app(TelegramPublisher::class)->publish($delivery);

        $this->assertSame(['654'], $result['message_ids']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tgprx.orangepanda.ru/bottest-token/sendVideo'
            && $this->multipartValue($request, 'chat_id') === '@poka_trend'
            && $this->multipartValue($request, 'caption') === '<b>Видео дня</b>'
            && $this->multipartValue($request, 'parse_mode') === 'HTML'
            && (int) $this->multipartValue($request, 'width') === 1080
            && (int) $this->multipartValue($request, 'height') === 1920
            && (int) $this->multipartValue($request, 'duration') === 15
            && (bool) $this->multipartValue($request, 'supports_streaming')
            && $this->multipartValue($request, 'thumbnail') === 'attach://thumbnail'
            && $request->hasFile('video', 'original-video', 'video.mp4')
            && $request->hasFile('thumbnail', 'preview', 'preview.jpg'));
    }

    public function test_animation_is_sent_as_a_telegram_animation(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('telegram/animation.mp4', 'animation');
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.bot_api_url' => 'https://tgprx.orangepanda.ru/',
        ]);
        Http::fake([
            'https://tgprx.orangepanda.ru/bottest-token/sendAnimation' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 987],
            ]),
        ]);
        $publication = Publication::factory()->create();
        $destination = Destination::factory()->create(['publication_id' => $publication->id, 'external_id' => '@poka_trend']);
        $plan = ContentPlan::factory()->create(['publication_id' => $publication->id]);
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'text' => '**GIF дня**',
        ]);
        MediaAsset::factory()->for($post, 'mediable')->create([
            'type' => MediaType::Animation,
            'path' => 'telegram/animation.mp4',
            'mime_type' => 'video/mp4',
            'metadata' => [
                'width' => 640,
                'height' => 360,
                'duration' => 5,
            ],
        ]);
        $delivery = Delivery::factory()->create(['planned_post_id' => $post->id, 'destination_id' => $destination->id]);

        $result = app(TelegramPublisher::class)->publish($delivery);

        $this->assertSame(['987'], $result['message_ids']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tgprx.orangepanda.ru/bottest-token/sendAnimation'
            && $this->multipartValue($request, 'chat_id') === '@poka_trend'
            && $this->multipartValue($request, 'caption') === '<b>GIF дня</b>'
            && $this->multipartValue($request, 'parse_mode') === 'HTML'
            && (int) $this->multipartValue($request, 'width') === 640
            && (int) $this->multipartValue($request, 'height') === 360
            && (int) $this->multipartValue($request, 'duration') === 5
            && $request->hasFile('animation', 'animation', 'animation.mp4'));
    }

    public function test_telegram_client_uses_configured_publish_timeouts(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.bot_api_timeout' => 300,
            'services.telegram.bot_api_connect_timeout' => 10,
        ]);
        $method = new ReflectionMethod(TelegramPublisher::class, 'client');
        $client = $method->invoke(app(TelegramPublisher::class));
        $property = new ReflectionProperty(PendingRequest::class, 'options');
        $options = $property->getValue($client);

        $this->assertSame(300, $options['timeout']);
        $this->assertSame(10, $options['connect_timeout']);
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

    private function multipartValue(Request $request, string $name): mixed
    {
        return collect($request->data())->firstWhere('name', $name)['contents'] ?? null;
    }
}
