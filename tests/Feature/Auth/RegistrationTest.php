<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('register.store'));
    }

    public function test_guests_cannot_access_admin_user_creation(): void
    {
        $this->get(route('filament.admin.resources.users.create'))
            ->assertRedirect(route('filament.admin.auth.login'));
    }
}
