<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_password_reset_request_screen_can_be_rendered(): void
    {
        $this->get(route('filament.admin.auth.password-reset.request'))->assertOk();
    }

    public function test_public_fortify_password_reset_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('password.request'));
        $this->assertFalse(Route::has('password.email'));
        $this->assertFalse(Route::has('password.reset'));
        $this->assertFalse(Route::has('password.update'));
    }
}
