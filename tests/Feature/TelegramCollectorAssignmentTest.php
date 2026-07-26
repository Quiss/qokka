<?php

namespace Tests\Feature;

use App\Actions\AssignTelegramCollector;
use App\Actions\ReconcileTelegramCollectors;
use App\Actions\SubscribeTelegramCollectorToSource;
use App\Contracts\MadelineClient;
use App\Filament\Resources\SourceChannels\Pages\CreateSourceChannel;
use App\Filament\Resources\SourceChannels\Pages\EditSourceChannel;
use App\Filament\Resources\SourceChannels\Pages\ListSourceChannels;
use App\Jobs\VerifySourceChannelAccessJob;
use App\Models\SourceChannel;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\MadelineClientFactory;
use App\Services\MadelineClientPool;
use App\TelegramAccountStatus;
use App\TelegramSourceAccessStatus;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TelegramCollectorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_can_be_created_with_a_preferred_collector(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);
        $preferred = TelegramAccount::factory()->create();

        $this->actingAs($user);

        Livewire::test(CreateSourceChannel::class)
            ->fillForm([
                'username' => '@trendi',
                'title' => 'Тренды',
                'weight' => 1,
                'preferred_collector_telegram_account_id' => $preferred->id,
                'sourceGroups' => [],
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sourceChannel = SourceChannel::query()->where('username', 'trendi')->firstOrFail();

        $this->assertTrue($sourceChannel->preferredCollectorTelegramAccount?->is($preferred));
        Queue::assertPushed(
            VerifySourceChannelAccessJob::class,
            fn (VerifySourceChannelAccessJob $job): bool => $job->sourceChannelId === $sourceChannel->id,
        );
    }

    public function test_preferred_collector_can_be_changed_or_cleared_on_update(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);
        $first = TelegramAccount::factory()->create();
        $second = TelegramAccount::factory()->create();
        $sourceChannel = SourceChannel::factory()->create([
            'preferred_collector_telegram_account_id' => $first->id,
        ]);

        $this->actingAs($user);

        Livewire::test(EditSourceChannel::class, ['record' => $sourceChannel->id])
            ->fillForm(['preferred_collector_telegram_account_id' => $second->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($sourceChannel->fresh()->preferredCollectorTelegramAccount?->is($second));

        Livewire::test(EditSourceChannel::class, ['record' => $sourceChannel->id])
            ->fillForm(['preferred_collector_telegram_account_id' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($sourceChannel->fresh()->preferred_collector_telegram_account_id);
        Queue::assertPushed(
            VerifySourceChannelAccessJob::class,
            fn (VerifySourceChannelAccessJob $job): bool => $job->sourceChannelId === $sourceChannel->id,
        );
    }

    public function test_preferred_collector_wins_over_a_less_loaded_account(): void
    {
        $preferred = $this->connectedAccount();
        $lessLoaded = $this->connectedAccount();
        SourceChannel::factory()->create([
            'username' => null,
            'collector_telegram_account_id' => $preferred->id,
        ]);
        $sourceChannel = SourceChannel::factory()->create([
            'username' => null,
            'collector_telegram_account_id' => null,
            'preferred_collector_telegram_account_id' => $preferred->id,
        ]);
        $sourceChannel->telegramAccounts()->attach([
            $preferred->id => ['access_status' => TelegramSourceAccessStatus::Available],
            $lessLoaded->id => ['access_status' => TelegramSourceAccessStatus::Available],
        ]);

        $selected = app(AssignTelegramCollector::class)->handle($sourceChannel);

        $this->assertTrue($selected?->is($preferred));
        $this->assertTrue($sourceChannel->fresh()->collectorTelegramAccount?->is($preferred));
    }

    public function test_public_source_is_joined_and_muted_before_collector_is_assigned(): void
    {
        $account = $this->connectedAccount();
        $sourceChannel = SourceChannel::factory()->create([
            'username' => 'trendi',
            'collector_telegram_account_id' => null,
        ]);
        $sourceChannel->telegramAccounts()->attach($account->id, [
            'access_status' => TelegramSourceAccessStatus::Available,
        ]);
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('joinChannel')
            ->once()
            ->with('@trendi')
            ->ordered()
            ->andReturnUsing(function () use ($sourceChannel): void {
                $this->assertNull($sourceChannel->fresh()->collector_telegram_account_id);
            });
        $client->shouldReceive('muteNotifications')
            ->once()
            ->with('@trendi')
            ->ordered();

        $selected = $this->assignmentAction([$account->uuid => $client])->handle($sourceChannel);

        $this->assertTrue($selected?->is($account));
        $this->assertTrue($sourceChannel->fresh()->collectorTelegramAccount?->is($account));
    }

    public function test_join_failure_marks_preferred_unavailable_and_uses_backup(): void
    {
        $preferred = $this->connectedAccount();
        $backup = $this->connectedAccount();
        $sourceChannel = SourceChannel::factory()->create([
            'username' => 'trendi',
            'collector_telegram_account_id' => null,
            'preferred_collector_telegram_account_id' => $preferred->id,
        ]);
        $sourceChannel->telegramAccounts()->attach([
            $preferred->id => ['access_status' => TelegramSourceAccessStatus::Available],
            $backup->id => ['access_status' => TelegramSourceAccessStatus::Available],
        ]);
        $preferredClient = Mockery::mock(MadelineClient::class);
        $preferredClient->shouldReceive('joinChannel')
            ->once()
            ->with('@trendi')
            ->andThrow(new RuntimeException('CHANNELS_TOO_MUCH'));
        $backupClient = Mockery::mock(MadelineClient::class);
        $backupClient->shouldReceive('joinChannel')->once()->with('@trendi');
        $backupClient->shouldReceive('muteNotifications')->once()->with('@trendi');

        $selected = $this->assignmentAction([
            $preferred->uuid => $preferredClient,
            $backup->uuid => $backupClient,
        ])->handle($sourceChannel);

        $preferredAccess = $sourceChannel->telegramAccounts()
            ->whereKey($preferred->id)
            ->firstOrFail()
            ->pivot;

        $this->assertTrue($selected?->is($backup));
        $this->assertSame($preferred->id, $sourceChannel->fresh()->preferred_collector_telegram_account_id);
        $this->assertTrue($sourceChannel->fresh()->collectorTelegramAccount?->is($backup));
        $this->assertSame(TelegramSourceAccessStatus::Unavailable->value, $preferredAccess->access_status);
        $this->assertSame('CHANNELS_TOO_MUCH', $preferredAccess->last_error);
    }

    public function test_already_participant_error_is_treated_as_success_and_notifications_are_muted(): void
    {
        $account = $this->connectedAccount();
        $sourceChannel = SourceChannel::factory()->create(['username' => 'trendi']);
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('joinChannel')
            ->once()
            ->with('@trendi')
            ->andThrow(RPCErrorException::make(
                'USER_ALREADY_PARTICIPANT',
                400,
                'channels.joinChannel',
            ));
        $client->shouldReceive('muteNotifications')->once()->with('@trendi');
        $subscriber = $this->subscriptionAction([$account->uuid => $client]);

        $subscriber->handle($account, $sourceChannel);

        $this->addToAssertionCount(1);
    }

    public function test_private_source_does_not_attempt_to_join(): void
    {
        $account = $this->connectedAccount();
        $sourceChannel = SourceChannel::factory()->create([
            'username' => null,
            'collector_telegram_account_id' => null,
        ]);
        $sourceChannel->telegramAccounts()->attach($account->id, [
            'access_status' => TelegramSourceAccessStatus::Available,
        ]);
        $factory = Mockery::mock(MadelineClientFactory::class);
        $factory->shouldNotReceive('make');
        $action = new AssignTelegramCollector(
            new SubscribeTelegramCollectorToSource(new MadelineClientPool($factory)),
        );

        $selected = $action->handle($sourceChannel);

        $this->assertTrue($selected?->is($account));
    }

    public function test_reconciliation_keeps_a_healthy_backup_then_returns_to_preferred(): void
    {
        $preferred = $this->connectedAccount();
        $backup = $this->connectedAccount();
        $sourceChannel = SourceChannel::factory()->create([
            'username' => null,
            'collector_telegram_account_id' => $backup->id,
            'preferred_collector_telegram_account_id' => $preferred->id,
        ]);
        $sourceChannel->telegramAccounts()->attach([
            $preferred->id => ['access_status' => TelegramSourceAccessStatus::Unavailable],
            $backup->id => ['access_status' => TelegramSourceAccessStatus::Available],
        ]);

        $this->assertSame(0, app(ReconcileTelegramCollectors::class)->handle());
        $this->assertTrue($sourceChannel->fresh()->collectorTelegramAccount?->is($backup));

        $sourceChannel->telegramAccounts()->updateExistingPivot($preferred->id, [
            'access_status' => TelegramSourceAccessStatus::Available,
            'last_error' => null,
        ]);

        $this->assertSame(1, app(ReconcileTelegramCollectors::class)->handle());
        $this->assertTrue($sourceChannel->fresh()->collectorTelegramAccount?->is($preferred));
    }

    public function test_reconciliation_retries_a_failed_public_preferred_subscription_after_cooldown(): void
    {
        $preferred = $this->connectedAccount();
        $backup = $this->connectedAccount();
        $sourceChannel = SourceChannel::factory()->create([
            'username' => 'trendi',
            'collector_telegram_account_id' => $backup->id,
            'preferred_collector_telegram_account_id' => $preferred->id,
        ]);
        $sourceChannel->telegramAccounts()->attach([
            $preferred->id => [
                'access_status' => TelegramSourceAccessStatus::Unavailable,
                'last_checked_at' => now()->subMinutes(6),
                'last_error' => 'CHANNELS_TOO_MUCH',
            ],
            $backup->id => [
                'access_status' => TelegramSourceAccessStatus::Available,
                'last_checked_at' => now(),
                'last_error' => null,
            ],
        ]);
        $preferredClient = Mockery::mock(MadelineClient::class);
        $preferredClient->shouldReceive('joinChannel')->once()->with('@trendi');
        $preferredClient->shouldReceive('muteNotifications')->once()->with('@trendi');
        $reconciler = new ReconcileTelegramCollectors(
            $this->assignmentAction([$preferred->uuid => $preferredClient]),
        );

        $this->assertSame(1, $reconciler->handle());

        $preferredAccess = $sourceChannel->telegramAccounts()
            ->whereKey($preferred->id)
            ->firstOrFail()
            ->pivot;

        $this->assertTrue($sourceChannel->fresh()->collectorTelegramAccount?->is($preferred));
        $this->assertSame(TelegramSourceAccessStatus::Available->value, $preferredAccess->access_status);
        $this->assertNull($preferredAccess->last_error);
    }

    public function test_verification_mode_rejoins_an_existing_public_collector(): void
    {
        $account = $this->connectedAccount();
        $sourceChannel = SourceChannel::factory()->create([
            'username' => 'trendi',
            'collector_telegram_account_id' => $account->id,
        ]);
        $sourceChannel->telegramAccounts()->attach($account->id, [
            'access_status' => TelegramSourceAccessStatus::Available,
        ]);
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('joinChannel')->once()->with('@trendi');
        $client->shouldReceive('muteNotifications')->once()->with('@trendi');

        $selected = $this->assignmentAction([$account->uuid => $client])->handle(
            $sourceChannel,
            ensureCurrentSubscription: true,
        );

        $this->assertTrue($selected?->is($account));
    }

    public function test_source_table_shows_preferred_current_fallback_and_error(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $preferred = $this->connectedAccount(['name' => 'Основной']);
        $backup = $this->connectedAccount(['name' => 'Резерв']);
        $sourceChannel = SourceChannel::factory()->create([
            'username' => null,
            'preferred_collector_telegram_account_id' => $preferred->id,
            'collector_telegram_account_id' => $backup->id,
        ]);
        $sourceChannel->telegramAccounts()->attach([
            $preferred->id => [
                'access_status' => TelegramSourceAccessStatus::Unavailable,
                'last_error' => 'CHANNELS_TOO_MUCH',
            ],
            $backup->id => [
                'access_status' => TelegramSourceAccessStatus::Available,
                'last_error' => null,
            ],
        ]);

        $this->actingAs($user);

        Livewire::test(ListSourceChannels::class)
            ->assertCanSeeTableRecords([$sourceChannel])
            ->assertSee('Основной')
            ->assertSee('Резерв')
            ->assertSee('Резервный')
            ->assertSee('CHANNELS_TOO_MUCH');
    }

    /**
     * @param  array<string, MadelineClient>  $clientsByUuid
     */
    private function assignmentAction(array $clientsByUuid): AssignTelegramCollector
    {
        return new AssignTelegramCollector($this->subscriptionAction($clientsByUuid));
    }

    /**
     * @param  array<string, MadelineClient>  $clientsByUuid
     */
    private function subscriptionAction(array $clientsByUuid): SubscribeTelegramCollectorToSource
    {
        $factory = Mockery::mock(MadelineClientFactory::class);
        $factory->shouldReceive('make')
            ->andReturnUsing(
                fn (TelegramAccount $account): MadelineClient => $clientsByUuid[$account->uuid],
            );

        return new SubscribeTelegramCollectorToSource(new MadelineClientPool($factory));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function connectedAccount(array $attributes = []): TelegramAccount
    {
        return TelegramAccount::factory()->create(array_merge([
            'status' => TelegramAccountStatus::Connected,
            'last_seen_at' => now(),
        ], $attributes));
    }
}
