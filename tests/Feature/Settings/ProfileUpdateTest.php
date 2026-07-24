<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_profile_page_is_displayed(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->get(route('filament.admin.auth.profile'))
            ->assertOk();
    }

    public function test_public_profile_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('profile.edit'));
        $this->assertFalse(Route::has('profile.update'));
        $this->assertFalse(Route::has('profile.destroy'));
    }
}
