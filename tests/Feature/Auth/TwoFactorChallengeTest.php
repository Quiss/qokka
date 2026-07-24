<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_factor_authentication_is_disabled(): void
    {
        $this->assertNotContains(
            Features::twoFactorAuthentication(),
            config('fortify.features'),
        );
    }

    public function test_two_factor_challenge_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('two-factor.login'));
        $this->assertFalse(Route::has('two-factor.enable'));
        $this->assertFalse(Route::has('two-factor.confirm'));
    }

    public function test_users_do_not_implement_filament_app_authentication(): void
    {
        $this->assertNotInstanceOf(HasAppAuthentication::class, new User);
    }
}
