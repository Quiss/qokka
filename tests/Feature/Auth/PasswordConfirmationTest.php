<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_password_confirmation_route_is_not_registered(): void
    {
        $this->assertFalse(Route::has('password.confirm'));
    }
}
