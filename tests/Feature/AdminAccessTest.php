<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_filament_admin(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_only_active_users_can_access_admin_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue(User::factory()->make(['is_active' => true])->canAccessPanel($panel));
        $this->assertFalse(User::factory()->make(['is_active' => false])->canAccessPanel($panel));
    }

    public function test_active_admin_can_access_panel_without_two_factor_authentication(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();

        $this->get('/admin/profile')
            ->assertOk()
            ->assertDontSee('Two-factor authentication');
    }

    public function test_two_factor_authentication_is_disabled_everywhere(): void
    {
        $this->assertFalse(Features::enabled(Features::twoFactorAuthentication()));

        $this->get('/two-factor-challenge')->assertNotFound();
        $this->get('/admin/multi-factor-authentication/set-up')->assertNotFound();
    }

    public function test_filament_mfa_secrets_are_hidden(): void
    {
        $user = User::factory()->create([
            'app_authentication_secret' => encrypt('secret'),
            'app_authentication_recovery_codes' => encrypt(['code']),
        ]);

        $this->assertArrayNotHasKey('app_authentication_secret', $user->toArray());
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $user->toArray());
    }
}
