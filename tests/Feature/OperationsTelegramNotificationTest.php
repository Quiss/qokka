<?php

namespace Tests\Feature;

use App\Actions\AdvanceContentPlanSafetyNet;
use App\Actions\FinalizeContentPlanSafetyNet;
use App\Actions\GenerateCandidateBatch;
use App\Actions\QueueOperationsNotification;
use App\Actions\RecoverStaleDeliveryPublications;
use App\ContentPlanStatus;
use App\Contracts\OperationsNotifier;
use App\DeliveryStatus;
use App\Jobs\DownloadMediaAssetJob;
use App\Jobs\GenerateCandidateBatchJob;
use App\Jobs\PublishDeliveryJob;
use App\Jobs\SendOperationsNotificationJob;
use App\Listeners\QueueFailedJobOperationsNotification;
use App\Listeners\QueueFailedScheduledTaskOperationsNotification;
use App\Models\ContentPlan;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\OperationsNotificationTopic;
use App\PlannedPostStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OperationsTelegramNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_notifier_sends_to_content_plan_topic_with_safe_html_and_button(): void
    {
        $this->configureOperationsNotifications();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/botoperations-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 101],
            ]),
        ]);

        app(OperationsNotifier::class)->send(
            OperationsNotificationTopic::ContentPlans,
            'План <опасный>',
            ['Кандидатов: 7'],
            'https://qokka.test/admin/content-plans/10/edit',
        );

        Http::assertSent(function (Request $request): bool {
            $replyMarkup = $request['reply_markup'];

            return $request->url() === 'https://api.telegram.org/botoperations-token/sendMessage'
                && $request['chat_id'] === '-1004403081411'
                && $request['message_thread_id'] === 4
                && $request['parse_mode'] === 'HTML'
                && str_contains((string) $request['text'], 'План &lt;опасный&gt;')
                && is_array($replyMarkup)
                && data_get($replyMarkup, 'inline_keyboard.0.0.text') === 'Открыть'
                && data_get($replyMarkup, 'inline_keyboard.0.0.url') === 'https://qokka.test/admin/content-plans/10/edit';
        });
    }

    public function test_diagnostic_command_can_send_to_failures_topic(): void
    {
        $this->configureOperationsNotifications();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/botoperations-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 102],
            ]),
        ]);

        $this->artisan('telegram:operations:test', ['topic' => 'failures'])
            ->expectsOutput('Тестовое уведомление отправлено.')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['message_thread_id'] === 5
            && str_contains((string) $request['text'], 'Тест уведомлений Qokka'));
    }

    public function test_dispatcher_queues_configured_notification_on_default_queue(): void
    {
        $this->configureOperationsNotifications();
        Queue::fake();

        app(QueueOperationsNotification::class)->handle(
            OperationsNotificationTopic::Failures,
            'Терминальный сбой',
            ['Ошибка: тест'],
            'https://qokka.test/admin',
        );

        Queue::assertPushedOn(
            'default',
            SendOperationsNotificationJob::class,
            fn (SendOperationsNotificationJob $job): bool => $job->topic === OperationsNotificationTopic::Failures
                && $job->title === 'Терминальный сбой'
                && $job->details === ['Ошибка: тест']
                && $job->url === 'https://qokka.test/admin',
        );
    }

    public function test_dispatcher_does_not_queue_when_operations_chat_is_not_configured(): void
    {
        $this->configureOperationsNotifications();
        config(['services.telegram.operations.chat_id' => null]);
        Queue::fake();

        app(QueueOperationsNotification::class)->handle(
            OperationsNotificationTopic::ContentPlans,
            'Новый план',
            [],
            'https://qokka.test/admin',
        );

        Queue::assertNothingPushed();
    }

    public function test_initial_content_plan_generation_queues_notification_but_replenishment_does_not(): void
    {
        $this->configureOperationsNotifications();
        Queue::fake();
        $publication = Publication::factory()->create(['name' => 'ПокаТренд']);
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => now()->addDay()->toDateString(),
            'status' => ContentPlanStatus::Generating,
        ]);

        app(GenerateCandidateBatch::class)->handle($contentPlan);

        Queue::assertPushed(
            SendOperationsNotificationJob::class,
            fn (SendOperationsNotificationJob $job): bool => $job->topic === OperationsNotificationTopic::ContentPlans
                && $job->title === 'Собран новый контент-план для «ПокаТренд»'
                && in_array('Кандидатов: 0', $job->details, true)
                && str_contains($job->url, "/admin/content-plans/{$contentPlan->id}/edit"),
        );

        Queue::fake();
        app(GenerateCandidateBatch::class)->handle(
            $contentPlan->fresh(),
            append: true,
            targetOverride: 1,
        );

        Queue::assertNotPushed(SendOperationsNotificationJob::class);
    }

    public function test_safety_net_start_queues_one_notification_only(): void
    {
        $this->configureOperationsNotifications();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-26 00:10:00', 'Europe/Moscow'));
        $publication = Publication::factory()->create([
            'name' => 'ПокаТренд',
            'safety_net_cutoff_time' => '00:00',
        ]);
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-26',
            'status' => ContentPlanStatus::CandidateReview,
            'generated_at' => null,
        ]);

        app(AdvanceContentPlanSafetyNet::class)->handle($publication);
        app(AdvanceContentPlanSafetyNet::class)->handle($publication);

        Queue::assertPushed(
            SendOperationsNotificationJob::class,
            fn (SendOperationsNotificationJob $job): bool => $job->topic === OperationsNotificationTopic::ContentPlans
                && $job->title === 'План для «ПокаТренд» передан на автоматическую модерацию'
                && str_contains($job->url, "/admin/content-plans/{$contentPlan->id}/edit"),
        );
        Queue::assertPushed(SendOperationsNotificationJob::class, 1);
    }

    public function test_failed_queue_job_queues_terminal_failure_notification(): void
    {
        $this->configureOperationsNotifications();
        Queue::fake();
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('resolveName')->once()->andReturn(GenerateCandidateBatchJob::class);
        $queueJob->shouldReceive('getQueue')->once()->andReturn('ai');
        $event = new JobFailed('redis', $queueJob, new RuntimeException('OpenRouter недоступен'));

        app(QueueFailedJobOperationsNotification::class)->handle($event);

        Queue::assertPushed(
            SendOperationsNotificationJob::class,
            fn (SendOperationsNotificationJob $job): bool => $job->topic === OperationsNotificationTopic::Failures
                && $job->title === 'Терминальный сбой: не удалось собрать контент-план'
                && in_array('Очередь: redis/ai', $job->details, true)
                && in_array('Ошибка: OpenRouter недоступен', $job->details, true)
                && str_ends_with($job->url, '/horizon/failed'),
        );
    }

    public function test_failed_media_job_notification_uses_the_last_transport_error(): void
    {
        $this->configureOperationsNotifications();
        Queue::fake();
        $sourcePost = SourcePost::factory()->create();
        $asset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'path' => null,
            'downloaded_at' => null,
            'metadata' => [
                'download_last_error' => [
                    'code' => 'CancelledException',
                    'message' => 'The operation was cancelled after the Telegram RPC timeout.',
                    'telegram_account_id' => 7,
                ],
            ],
        ]);
        $downloadJob = new DownloadMediaAssetJob($asset->id);
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('resolveName')->once()->andReturn(DownloadMediaAssetJob::class);
        $queueJob->shouldReceive('getQueue')->once()->andReturn('media-download-high');
        $queueJob->shouldReceive('payload')->once()->andReturn([
            'data' => ['command' => serialize($downloadJob)],
        ]);

        app(QueueFailedJobOperationsNotification::class)->handle(
            new JobFailed(
                'redis',
                $queueJob,
                new RuntimeException('DownloadMediaAssetJob has been attempted too many times.'),
            ),
        );

        Queue::assertPushed(
            SendOperationsNotificationJob::class,
            fn (SendOperationsNotificationJob $job): bool => in_array(
                'Ошибка: The operation was cancelled after the Telegram RPC timeout.',
                $job->details,
                true,
            ) && collect($job->details)->contains(
                fn (string $detail): bool => str_contains($detail, "Медиа: #{$asset->id}")
                    && str_contains($detail, 'аккаунт: 7'),
            ),
        );
    }

    public function test_notification_job_failure_does_not_recursively_queue_another_notification(): void
    {
        $this->configureOperationsNotifications();
        Queue::fake();
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('resolveName')->once()->andReturn(SendOperationsNotificationJob::class);

        app(QueueFailedJobOperationsNotification::class)->handle(
            new JobFailed('redis', $queueJob, new RuntimeException('Telegram недоступен')),
        );

        Queue::assertNothingPushed();
    }

    public function test_failed_scheduled_task_queues_terminal_failure_notification(): void
    {
        $this->configureOperationsNotifications();
        Queue::fake();
        $scheduledTask = Mockery::mock(ScheduledEvent::class);
        $scheduledTask->shouldReceive('getSummaryForDisplay')
            ->once()
            ->andReturn('content-plans:generate-due');

        app(QueueFailedScheduledTaskOperationsNotification::class)->handle(
            new ScheduledTaskFailed($scheduledTask, new RuntimeException('scheduler failed')),
        );

        Queue::assertPushed(
            SendOperationsNotificationJob::class,
            fn (SendOperationsNotificationJob $job): bool => $job->topic === OperationsNotificationTopic::Failures
                && $job->title === 'Терминальный сбой планировщика'
                && in_array('Задача: content-plans:generate-due', $job->details, true),
        );
    }

    public function test_safety_net_terminal_configuration_failure_is_reported(): void
    {
        $this->configureOperationsNotifications();
        Queue::fake();
        $publication = Publication::factory()->create(['name' => 'ПокаТренд']);
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => now()->toDateString(),
            'status' => ContentPlanStatus::FinalReview,
            'safety_net_started_at' => now(),
        ]);
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $contentPlan->id,
        ]);
        PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $candidate->id,
            'status' => PlannedPostStatus::FinalReview,
            'risk_flags' => [],
            'ai_review_status' => 'passed',
        ]);

        app(FinalizeContentPlanSafetyNet::class)->handle($contentPlan);

        $this->assertSame(ContentPlanStatus::Failed, $contentPlan->fresh()->status);
        Queue::assertPushed(
            SendOperationsNotificationJob::class,
            fn (SendOperationsNotificationJob $job): bool => $job->topic === OperationsNotificationTopic::Failures
                && str_contains($job->title, 'Терминальный сбой автоматической модерации')
                && str_contains($job->url, "/admin/content-plans/{$contentPlan->id}/edit"),
        );
    }

    public function test_stale_publication_recovery_queues_one_aggregated_failure_notification(): void
    {
        $this->configureOperationsNotifications();
        config(['services.telegram.publishing_stale_after' => 600]);
        Queue::fake();
        $staleDelivery = $this->deliveryForPublication();
        $staleDelivery->update(['status' => DeliveryStatus::Publishing]);
        Delivery::query()
            ->whereKey($staleDelivery->id)
            ->update(['updated_at' => now()->subMinutes(11)]);

        $this->assertSame(1, app(RecoverStaleDeliveryPublications::class)->handle());

        Queue::assertPushed(
            SendOperationsNotificationJob::class,
            fn (SendOperationsNotificationJob $job): bool => $job->topic === OperationsNotificationTopic::Failures
                && $job->title === 'Обнаружены зависшие публикации'
                && in_array('Delivery: #'.$staleDelivery->id, $job->details, true)
                && str_contains($job->url, '/admin/deliveries'),
        );
        Queue::assertPushed(SendOperationsNotificationJob::class, 1);
    }

    public function test_publish_retry_is_silent_but_terminal_connection_failure_is_reported(): void
    {
        $this->configureOperationsNotifications();
        Queue::fake();
        $retryDelivery = $this->deliveryForPublication();
        Http::fake(['*' => Http::response(['ok' => false], 500)]);

        app()->call([(new PublishDeliveryJob($retryDelivery->id)), 'handle']);

        $this->assertSame(DeliveryStatus::RetryScheduled, $retryDelivery->fresh()->status);
        Queue::assertNotPushed(SendOperationsNotificationJob::class);

        $terminalDelivery = $this->deliveryForPublication();
        Http::fake(['*' => Http::failedConnection('operation timed out')]);

        app()->call([(new PublishDeliveryJob($terminalDelivery->id)), 'handle']);

        $this->assertSame(DeliveryStatus::NeedsReview, $terminalDelivery->fresh()->status);
        Queue::assertPushed(
            SendOperationsNotificationJob::class,
            fn (SendOperationsNotificationJob $job): bool => $job->topic === OperationsNotificationTopic::Failures
                && str_contains($job->title, 'Терминальный сбой публикации')
                && in_array('Delivery: #'.$terminalDelivery->id, $job->details, true)
                && str_contains($job->url, '/admin/deliveries'),
        );
    }

    private function configureOperationsNotifications(): void
    {
        config([
            'services.telegram.bot_token' => 'operations-token',
            'services.telegram.bot_api_url' => 'https://api.telegram.org',
            'services.telegram.operations.chat_id' => '-1004403081411',
            'services.telegram.operations.topics.content_plans' => 4,
            'services.telegram.operations.topics.failures' => 5,
            'services.telegram.operations.timeout' => 10,
            'services.telegram.operations.connect_timeout' => 3,
        ]);
    }

    private function deliveryForPublication(): Delivery
    {
        $publication = Publication::factory()->create(['name' => 'ПокаТренд']);
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'status' => ContentPlanStatus::Active,
        ]);
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $contentPlan->id,
        ]);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $candidate->id,
            'status' => PlannedPostStatus::Approved,
        ]);
        $destination = Destination::factory()->create([
            'publication_id' => $publication->id,
        ]);

        return Delivery::factory()->create([
            'planned_post_id' => $plannedPost->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Pending,
            'attempts' => 0,
        ]);
    }
}
