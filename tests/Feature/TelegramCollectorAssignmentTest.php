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
use App\Models\SourceGroup;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\MadelineClientFactory;
use App\Services\MadelineClientPool;
use App\TelegramAccountStatus;
use App\TelegramSourceAccessStatus;
use danog\MadelineProto\RPCErrorException;
use Filament\Facades\Filament;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ViewColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Number;
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

    public function test_duplicate_public_source_is_shown_as_a_form_error_after_username_normalization(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        SourceChannel::factory()->create(['username' => 'smotret_skachatt']);

        $this->actingAs($user);

        Livewire::test(CreateSourceChannel::class)
            ->fillForm([
                'username' => 'https://t.me/smotret_skachatt/',
                'weight' => 1,
                'sourceGroups' => [],
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['username' => 'unique'])
            ->assertSee('Этот Telegram-канал уже добавлен в источники.');

        $this->assertSame(1, SourceChannel::query()->count());
    }

    public function test_duplicate_private_source_is_shown_as_a_form_error(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        SourceChannel::factory()->create([
            'username' => null,
            'telegram_peer_id' => -1001234567890,
        ]);

        $this->actingAs($user);

        Livewire::test(CreateSourceChannel::class)
            ->fillForm([
                'telegram_peer_id' => -1001234567890,
                'weight' => 1,
                'sourceGroups' => [],
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['telegram_peer_id' => 'unique'])
            ->assertSee('Источник с таким Telegram peer ID уже добавлен.');

        $this->assertSame(1, SourceChannel::query()->count());
    }

    public function test_concurrent_duplicate_source_insert_is_converted_to_a_form_error(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $panel = Filament::getPanel('admin');

        SourceChannel::creating(function (SourceChannel $sourceChannel): void {
            if ($sourceChannel->username !== 'race_condition') {
                return;
            }

            SourceChannel::withoutEvents(fn (): SourceChannel => SourceChannel::query()->create([
                'username' => 'race_condition',
                'title' => 'Источник из параллельного запроса',
                'weight' => 1,
                'is_active' => true,
            ]));
        });

        $this->actingAs($user);

        $panel->databaseTransactions();

        try {
            Livewire::test(CreateSourceChannel::class)
                ->fillForm([
                    'username' => '@race_condition',
                    'weight' => 1,
                    'sourceGroups' => [],
                    'is_active' => true,
                ])
                ->call('create')
                ->assertHasFormErrors(['username'])
                ->assertSee('Этот Telegram-канал уже добавлен в источники.');

            $this->assertSame(0, SourceChannel::query()->count());
        } finally {
            $panel->databaseTransactions(false);
        }
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

    public function test_reconciliation_preserves_a_stale_collector_when_no_healthy_replacement_exists(): void
    {
        $stale = TelegramAccount::factory()->create([
            'status' => TelegramAccountStatus::Connected,
            'last_seen_at' => now()->subMinutes(4),
        ]);
        $sourceChannel = SourceChannel::factory()->create([
            'username' => null,
            'collector_telegram_account_id' => $stale->id,
        ]);
        $sourceChannel->telegramAccounts()->attach($stale->id, [
            'access_status' => TelegramSourceAccessStatus::Available,
        ]);

        $this->assertSame(0, app(ReconcileTelegramCollectors::class)->handle());
        $this->assertTrue($sourceChannel->fresh()->collectorTelegramAccount?->is($stale));
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
            'username' => 'trendi',
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
            ->assertTableColumnHasDescription('title', '@trendi', $sourceChannel)
            ->assertTableColumnFormattedStateSet('collector', 'Резервный', $sourceChannel)
            ->assertTableColumnExists(
                'statistics',
                checkColumnUsing: fn (Column $column): bool => $column instanceof ViewColumn
                    && $column->isToggleable()
                    && $column->getView() === 'filament.tables.columns.source-channel-statistics',
            )
            ->assertTableColumnDoesNotExist('posts_last_day_count')
            ->assertTableColumnDoesNotExist('views_last_day')
            ->assertTableColumnDoesNotExist('reactions_last_day')
            ->assertTableColumnDoesNotExist('forwards_last_day')
            ->assertTableColumnDoesNotExist('comments_last_day')
            ->assertSee('Основной')
            ->assertSee('Резерв')
            ->assertSee('Резервный')
            ->assertSee('CHANNELS_TOO_MUCH');
    }

    public function test_source_statistics_column_shows_an_icon_and_number_for_each_metric(): void
    {
        $sourceChannel = SourceChannel::factory()->make()->forceFill([
            'posts_last_day_count' => 12,
            'views_last_day' => 1_592,
            'reactions_last_day' => 347,
            'forwards_last_day' => 56,
            'comments_last_day' => 8,
        ]);

        $html = view('filament.tables.columns.source-channel-statistics', [
            'record' => $sourceChannel,
        ])->render();

        $statistics = [
            'Публикации за 24 часа' => 12,
            'Просмотры за 24 часа' => 1_592,
            'Реакции за 24 часа' => 347,
            'Пересылки за 24 часа' => 56,
            'Комментарии за 24 часа' => 8,
        ];

        $this->assertSame(5, substr_count($html, '<svg'));

        foreach ($statistics as $label => $value) {
            $formattedValue = Number::format($value, locale: 'ru');

            $this->assertStringContainsString("title=\"{$label}\"", $html);
            $this->assertStringContainsString(
                "aria-label=\"{$label}: {$formattedValue}\"",
                $html,
            );
        }
    }

    public function test_source_table_can_filter_by_one_or_multiple_groups(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $firstGroup = SourceGroup::factory()->create(['name' => 'Город']);
        $secondGroup = SourceGroup::factory()->create(['name' => 'Культура']);
        $otherGroup = SourceGroup::factory()->create(['name' => 'Спорт']);
        $firstMatch = SourceChannel::factory()->create(['title' => 'Городской источник']);
        $secondMatch = SourceChannel::factory()->create(['title' => 'Культурный источник']);
        $excluded = SourceChannel::factory()->create(['title' => 'Спортивный источник']);

        $firstMatch->sourceGroups()->attach($firstGroup);
        $secondMatch->sourceGroups()->attach($secondGroup);
        $excluded->sourceGroups()->attach($otherGroup);

        $this->actingAs($user);

        Livewire::test(ListSourceChannels::class)
            ->filterTable('sourceGroups', [$firstGroup, $secondGroup])
            ->assertCanSeeTableRecords([$firstMatch, $secondMatch])
            ->assertCanNotSeeTableRecords([$excluded])
            ->filterTable('sourceGroups', $firstGroup)
            ->assertCanSeeTableRecords([$firstMatch])
            ->assertCanNotSeeTableRecords([$secondMatch, $excluded]);
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
