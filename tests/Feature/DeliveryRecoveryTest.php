<?php

namespace Tests\Feature;

use App\Actions\CompleteDeliveryPublication;
use App\Actions\RecoverStaleDeliveryPublications;
use App\Actions\RetryDeliveryPublication;
use App\ContentPlanStatus;
use App\DeliveryStatus;
use App\Filament\Resources\Deliveries\Pages\ListDeliveries;
use App\Jobs\PublishDeliveryJob;
use App\Models\ContentPlan;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\PlannedPost;
use App\Models\StoryCandidate;
use App\Models\User;
use App\PlannedPostStatus;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use LogicException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class DeliveryRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambiguous_delivery_can_be_confirmed_as_published(): void
    {
        $user = User::factory()->create();
        [$plan, $plannedPost, $delivery] = $this->problemDelivery();

        $completed = app(CompleteDeliveryPublication::class)->handle($delivery, confirmedBy: $user);

        $this->assertSame(DeliveryStatus::Published, $completed->status);
        $this->assertNotNull($completed->published_at);
        $this->assertFalse($completed->is_ambiguous);
        $this->assertNull($completed->last_error);
        $this->assertSame('manually_confirmed_published', $completed->error_context['reason']);
        $this->assertSame($user->id, $completed->error_context['confirmed_by']);
        $this->assertSame('operation timed out', $completed->error_context['previous_error']);
        $this->assertSame(
            ['reason' => 'connection_lost_during_publish'],
            $completed->error_context['previous_context'],
        );
        $this->assertSame(PlannedPostStatus::Published, $plannedPost->fresh()->status);
        $this->assertSame(ContentPlanStatus::Completed, $plan->fresh()->status);
    }

    public function test_in_progress_delivery_can_be_confirmed_as_published(): void
    {
        $user = User::factory()->create();
        [$plan, $plannedPost, $delivery] = $this->problemDelivery();
        $delivery->update([
            'status' => DeliveryStatus::Publishing,
            'last_error' => null,
            'error_context' => null,
            'is_ambiguous' => false,
        ]);

        $completed = app(CompleteDeliveryPublication::class)->handle($delivery, confirmedBy: $user);

        $this->assertSame(DeliveryStatus::Published, $completed->status);
        $this->assertSame('manually_confirmed_published', $completed->error_context['reason']);
        $this->assertSame($user->id, $completed->error_context['confirmed_by']);
        $this->assertSame(PlannedPostStatus::Published, $plannedPost->fresh()->status);
        $this->assertSame(ContentPlanStatus::Completed, $plan->fresh()->status);
    }

    public function test_only_ambiguous_or_in_progress_delivery_can_be_manually_confirmed(): void
    {
        [, , $delivery] = $this->problemDelivery();
        $delivery->update(['status' => DeliveryStatus::Failed]);

        $this->expectException(LogicException::class);

        app(CompleteDeliveryPublication::class)->handle($delivery);
    }

    public function test_problem_delivery_can_be_retried_once_from_admin_request(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [, , $delivery] = $this->problemDelivery();

        $queued = app(RetryDeliveryPublication::class)->handle($delivery, $user);
        $alreadyQueued = app(RetryDeliveryPublication::class)->handle($delivery, $user);
        $delivery->refresh();

        $this->assertTrue($queued);
        $this->assertFalse($alreadyQueued);
        $this->assertSame(DeliveryStatus::RetryScheduled, $delivery->status);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertFalse($delivery->is_ambiguous);
        $this->assertNull($delivery->last_error);
        $this->assertSame('manual_retry_requested', $delivery->error_context['reason']);
        $this->assertSame($user->id, $delivery->error_context['requested_by']);
        $this->assertSame('operation timed out', $delivery->error_context['previous_error']);
        Queue::assertPushedOn(
            'publish',
            PublishDeliveryJob::class,
            fn (PublishDeliveryJob $job): bool => $job->deliveryId === $delivery->id,
        );
        Queue::assertPushed(PublishDeliveryJob::class, 1);
    }

    public function test_stale_in_progress_delivery_can_be_retried(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [, , $delivery] = $this->problemDelivery();
        $delivery->update([
            'status' => DeliveryStatus::Publishing,
        ]);
        Delivery::query()
            ->whereKey($delivery->id)
            ->update(['updated_at' => now()->subMinutes(11)]);
        $delivery->refresh();

        $queued = app(RetryDeliveryPublication::class)->handle($delivery, $user);
        $delivery->refresh();

        $this->assertTrue($queued);
        $this->assertSame(DeliveryStatus::RetryScheduled, $delivery->status);
        $this->assertSame('manual_retry_requested', $delivery->error_context['reason']);
        $this->assertSame($user->id, $delivery->error_context['requested_by']);
        Queue::assertPushedOn(
            'publish',
            PublishDeliveryJob::class,
            fn (PublishDeliveryJob $job): bool => $job->deliveryId === $delivery->id,
        );
        Queue::assertPushed(PublishDeliveryJob::class, 1);
    }

    public function test_active_in_progress_delivery_cannot_be_retried(): void
    {
        Queue::fake();
        [, , $delivery] = $this->problemDelivery();
        $delivery->update(['status' => DeliveryStatus::Publishing]);

        try {
            app(RetryDeliveryPublication::class)->handle($delivery);
            $this->fail('A delivery that may still be sending should not be retried.');
        } catch (LogicException) {
            $this->assertSame(DeliveryStatus::Publishing, $delivery->fresh()->status);
        }

        Queue::assertNothingPushed();
    }

    public function test_connection_timeout_requires_review_without_automatic_retry(): void
    {
        Queue::fake();
        [, , $delivery] = $this->problemDelivery();
        $delivery->update([
            'status' => DeliveryStatus::Pending,
            'attempts' => 0,
            'last_error' => null,
            'error_context' => null,
            'is_ambiguous' => false,
        ]);
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.bot_api_url' => 'https://api.telegram.org',
        ]);
        Http::fake(['*' => Http::failedConnection('operation timed out')]);

        app()->call([(new PublishDeliveryJob($delivery->id)), 'handle']);
        $delivery->refresh();

        $this->assertSame(DeliveryStatus::NeedsReview, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertTrue($delivery->is_ambiguous);
        $this->assertSame('connection_lost_during_publish', $delivery->error_context['reason']);
        Queue::assertNothingPushed();
    }

    public function test_state_update_failure_after_successful_publish_never_retries(): void
    {
        Queue::fake();
        [, , $delivery] = $this->problemDelivery();
        $delivery->update([
            'status' => DeliveryStatus::Pending,
            'attempts' => 0,
            'last_error' => null,
            'error_context' => null,
            'is_ambiguous' => false,
        ]);
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.bot_api_url' => 'https://api.telegram.org',
        ]);
        Http::fake(['*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 777],
        ])]);
        $this->mock(
            CompleteDeliveryPublication::class,
            fn (MockInterface $mock) => $mock
                ->shouldReceive('handle')
                ->once()
                ->andThrow(new RuntimeException('database update failed')),
        );

        app()->call([(new PublishDeliveryJob($delivery->id)), 'handle']);
        $delivery->refresh();

        $this->assertSame(DeliveryStatus::NeedsReview, $delivery->status);
        $this->assertSame(['777'], $delivery->external_message_ids);
        $this->assertTrue($delivery->is_ambiguous);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertSame('state_update_failed_after_publish', $delivery->error_context['reason']);
        Queue::assertNothingPushed();
    }

    public function test_terminal_job_failure_moves_in_progress_delivery_to_review(): void
    {
        [, , $delivery] = $this->problemDelivery();
        $delivery->update([
            'status' => DeliveryStatus::Publishing,
            'last_error' => null,
            'error_context' => ['reason' => 'sending'],
            'is_ambiguous' => false,
        ]);

        (new PublishDeliveryJob($delivery->id))->failed(new RuntimeException('worker timed out'));
        $delivery->refresh();

        $this->assertSame(DeliveryStatus::NeedsReview, $delivery->status);
        $this->assertSame('worker timed out', $delivery->last_error);
        $this->assertTrue($delivery->is_ambiguous);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertSame('publication_job_failed_while_sending', $delivery->error_context['reason']);
        $this->assertSame(RuntimeException::class, $delivery->error_context['exception']);
        $this->assertSame(['reason' => 'sending'], $delivery->error_context['previous_context']);
    }

    public function test_stale_publishing_deliveries_are_automatically_moved_to_review(): void
    {
        config(['services.telegram.publishing_stale_after' => 600]);
        [, , $staleDelivery] = $this->problemDelivery();
        [, , $activeDelivery] = $this->problemDelivery();
        $staleDelivery->update([
            'status' => DeliveryStatus::Publishing,
            'last_error' => null,
            'error_context' => ['reason' => 'sending'],
            'is_ambiguous' => false,
        ]);
        Delivery::query()
            ->whereKey($staleDelivery->id)
            ->update(['updated_at' => now()->subMinutes(11)]);
        $activeDelivery->update([
            'status' => DeliveryStatus::Publishing,
            'last_error' => null,
            'error_context' => null,
            'is_ambiguous' => false,
        ]);

        $recovered = app(RecoverStaleDeliveryPublications::class)->handle();
        $staleDelivery->refresh();

        $this->assertSame(1, $recovered);
        $this->assertSame(DeliveryStatus::NeedsReview, $staleDelivery->status);
        $this->assertTrue($staleDelivery->is_ambiguous);
        $this->assertNull($staleDelivery->next_attempt_at);
        $this->assertSame('stale_publishing_recovered', $staleDelivery->error_context['reason']);
        $this->assertSame(600, $staleDelivery->error_context['stale_after_seconds']);
        $this->assertSame(['reason' => 'sending'], $staleDelivery->error_context['previous_context']);
        $this->assertSame(DeliveryStatus::Publishing, $activeDelivery->fresh()->status);
    }

    public function test_deployment_wait_command_recovers_stale_publications(): void
    {
        config(['services.telegram.publishing_stale_after' => 600]);
        [, , $delivery] = $this->problemDelivery();
        $delivery->update(['status' => DeliveryStatus::Publishing]);
        Delivery::query()
            ->whereKey($delivery->id)
            ->update(['updated_at' => now()->subMinutes(11)]);

        $this->artisan('deliveries:wait-for-publishing', ['--timeout' => 0])
            ->expectsOutputToContain('Moved 1 stale publishing deliveries to manual review.')
            ->expectsOutputToContain('No active Telegram publications remain.')
            ->assertSuccessful();

        $this->assertSame(DeliveryStatus::NeedsReview, $delivery->fresh()->status);
    }

    public function test_deployment_wait_command_fails_while_publication_is_active(): void
    {
        [, , $delivery] = $this->problemDelivery();
        $delivery->update(['status' => DeliveryStatus::Publishing]);

        $this->artisan('deliveries:wait-for-publishing', ['--timeout' => 0])
            ->expectsOutputToContain('Timed out waiting for 1 active Telegram publications.')
            ->assertFailed();

        $this->assertSame(DeliveryStatus::Publishing, $delivery->fresh()->status);
    }

    public function test_recovery_is_scheduled_and_deploy_drains_publish_queue_before_pull(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('deliveries:recover-stale')
            ->assertSuccessful();

        $makefile = file_get_contents(base_path('Makefile'));

        $this->assertIsString($makefile);
        $pausePosition = strpos($makefile, 'queue:pause $(PUBLISH_QUEUE)');
        $pullPosition = strpos($makefile, 'git pull');
        $waitPosition = strpos($makefile, 'deliveries:wait-for-publishing');

        $this->assertIsInt($pausePosition);
        $this->assertIsInt($pullPosition);
        $this->assertIsInt($waitPosition);
        $this->assertTrue($pausePosition < $waitPosition);
        $this->assertTrue($waitPosition < $pullPosition);
        $this->assertStringContainsString('queue:continue $(PUBLISH_QUEUE)', $makefile);
        $this->assertStringContainsString("trap '", $makefile);
    }

    public function test_admin_can_confirm_ambiguous_delivery_from_delivery_table(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        [, $plannedPost, $delivery] = $this->problemDelivery();
        $this->actingAs($user);

        Livewire::test(ListDeliveries::class)
            ->assertActionVisible(TestAction::make('confirmPublished')->table($delivery))
            ->assertActionVisible(TestAction::make('retryPublication')->table($delivery))
            ->mountAction(TestAction::make('confirmPublished')->table($delivery))
            ->assertActionMounted(TestAction::make('confirmPublished')->table($delivery))
            ->callMountedAction()
            ->assertNotified('Доставка подтверждена как опубликованная');

        $this->assertSame(DeliveryStatus::Published, $delivery->fresh()->status);
        $this->assertSame(PlannedPostStatus::Published, $plannedPost->fresh()->status);
    }

    public function test_admin_can_retry_stale_in_progress_delivery_from_delivery_table(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);
        [, , $delivery] = $this->problemDelivery();
        $delivery->update([
            'status' => DeliveryStatus::Publishing,
            'last_error' => null,
            'error_context' => null,
            'is_ambiguous' => false,
        ]);
        Delivery::query()
            ->whereKey($delivery->id)
            ->update(['updated_at' => now()->subMinutes(11)]);
        $this->actingAs($user);

        Livewire::test(ListDeliveries::class)
            ->assertActionVisible(TestAction::make('confirmPublished')->table($delivery))
            ->assertActionVisible(TestAction::make('retryPublication')->table($delivery))
            ->mountAction(TestAction::make('retryPublication')->table($delivery))
            ->assertActionMounted(TestAction::make('retryPublication')->table($delivery))
            ->callMountedAction()
            ->assertNotified('Повторная отправка поставлена в очередь');

        $this->assertSame(DeliveryStatus::RetryScheduled, $delivery->fresh()->status);
        Queue::assertPushed(PublishDeliveryJob::class, 1);
    }

    /**
     * @return array{ContentPlan, PlannedPost, Delivery}
     */
    private function problemDelivery(): array
    {
        $plan = ContentPlan::factory()->create(['status' => ContentPlanStatus::Active]);
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'status' => PlannedPostStatus::Approved,
        ]);
        $destination = Destination::factory()->create(['publication_id' => $plan->publication_id]);
        $delivery = Delivery::factory()->create([
            'planned_post_id' => $plannedPost->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::NeedsReview,
            'attempts' => 1,
            'last_error' => 'operation timed out',
            'error_context' => ['reason' => 'connection_lost_during_publish'],
            'is_ambiguous' => true,
        ]);

        return [$plan, $plannedPost, $delivery];
    }
}
