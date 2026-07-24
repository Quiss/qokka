<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VerificationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_verification_notification_route_is_not_registered(): void
    {
        $this->assertFalse(Route::has('verification.send'));
    }
}
