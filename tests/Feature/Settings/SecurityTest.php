<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_factor_authentication_is_not_enabled(): void
    {
        $this->assertNotContains(
            Features::twoFactorAuthentication(),
            config('fortify.features'),
        );
    }

    public function test_public_security_and_password_update_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('security.edit'));
        $this->assertFalse(Route::has('user-password.update'));
        $this->assertFalse(Route::has('two-factor.enable'));
    }
}
