<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_login_screen_can_be_rendered(): void
    {
        $this->get(route('filament.admin.auth.login'))->assertOk();
    }

    public function test_public_fortify_login_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('login'));
        $this->assertFalse(Route::has('login.store'));
    }

    public function test_active_admin_can_access_the_panel(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_inactive_admin_is_denied_panel_access(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }
}
