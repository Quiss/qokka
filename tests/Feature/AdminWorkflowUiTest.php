<?php

namespace Tests\Feature;

use App\Models\ContentPlan;
use App\Models\TelegramAccount;
use App\Models\User;
use Tests\TestCase;

class AdminWorkflowUiTest extends TestCase
{
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
}
