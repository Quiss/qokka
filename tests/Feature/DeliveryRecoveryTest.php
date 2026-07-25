<?php

namespace Tests\Feature;

use App\Actions\CompleteDeliveryPublication;
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

    public function test_only_ambiguous_delivery_can_be_manually_confirmed(): void
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
