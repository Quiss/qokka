<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_the_admin_panel(): void
    {
        $this->get(route('home'))
            ->assertRedirect(route('filament.admin.pages.dashboard'));
    }
}
