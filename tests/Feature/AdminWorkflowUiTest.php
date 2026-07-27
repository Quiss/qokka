<?php

namespace Tests\Feature;

use App\ContentPlanStatus;
use App\Models\ContentPlan;
use App\Models\Publication;
use App\Models\TelegramAccount;
use App\Models\User;
use App\RiskFlagLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWorkflowUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_pages_render_for_an_active_admin(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $plan = ContentPlan::factory()->create();
        $telegramAccount = TelegramAccount::factory()->create();

        $this->actingAs($user);

        $this->get('/admin/content-plans')->assertOk()->assertSee('Редакция');
        $this->get("/admin/content-plans/{$plan->id}/edit")->assertOk()->assertSee('Отбор новостей');
        $this->get('/admin/source-channels/create')->assertOk()->assertSee('Ссылка или username');
        $this->get('/admin/source-groups/create')->assertOk()->assertSee('Источники');
        $this->get('/admin/publications/create')
            ->assertOk()
            ->assertSee('Редакционная инструкция для AI')
            ->assertSee('Страховочная автопубликация');
        $this->get('/admin/telegram-accounts')->assertOk()->assertSee('Telegram-аккаунты');
        $this->get("/admin/telegram-accounts/{$telegramAccount->id}/edit")->assertOk()->assertSee('Последний heartbeat');
    }

    public function test_content_plan_stages_have_distinct_badge_colors(): void
    {
        $serializedColors = array_map(
            fn (ContentPlanStatus $status): string => json_encode(
                $status->getColor(),
                JSON_THROW_ON_ERROR,
            ),
            ContentPlanStatus::cases(),
        );

        $this->assertCount(
            count(ContentPlanStatus::cases()),
            array_unique($serializedColors),
        );
        $this->assertSame('Утверждение плана', ContentPlanStatus::CandidateReview->getLabel());
        $this->assertSame('Рерайт', ContentPlanStatus::Rewriting->getLabel());
    }

    public function test_risk_flag_codes_have_human_readable_labels_without_changing_the_codes(): void
    {
        $this->assertSame(
            'Есть неподтверждённое утверждение',
            RiskFlagLabels::label('unsupported_claim'),
        );
        $this->assertSame(
            ['Источники расходятся в деталях', 'Информация устареет к публикации'],
            RiskFlagLabels::labels(['source_conflict', 'stale_at_publication']),
        );
        $this->assertSame('Требует ручной проверки', RiskFlagLabels::label('new_ai_risk'));
    }

    public function test_content_plans_are_sorted_by_editorial_priority(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach (ContentPlanStatus::cases() as $status) {
            $publication = Publication::factory()->create([
                'name' => 'Канал '.$status->value,
            ]);

            ContentPlan::factory()->for($publication)->create([
                'plan_date' => '2026-07-27',
                'status' => $status,
            ]);
        }

        $expectedPublicationOrder = array_map(
            fn (ContentPlanStatus $status): string => 'Канал '.$status->value,
            ContentPlanStatus::editorialPriority(),
        );

        $this->actingAs($user)
            ->get('/admin/content-plans')
            ->assertOk()
            ->assertSeeInOrder($expectedPublicationOrder);
    }
}
