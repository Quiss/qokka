<?php

namespace Tests\Feature;

use App\Filament\Resources\Publications\Pages\CreatePublication;
use App\Filament\Resources\Publications\Pages\EditPublication;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicationNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_notes_are_available_when_creating_a_publication(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreatePublication::class)
            ->assertActionVisible('notes')
            ->mountAction('notes')
            ->assertMountedActionModalSee('Как разделить каналы с общей базой источников')
            ->assertMountedActionModalSee('Новости Питера')
            ->assertMountedActionModalSee('Пример редакционной инструкции');
    }

    public function test_notes_are_available_when_editing_a_publication(): void
    {
        $this->actingAs(User::factory()->create());
        $publication = Publication::factory()->create();

        Livewire::test(EditPublication::class, ['record' => $publication->getRouteKey()])
            ->assertActionVisible('notes')
            ->mountAction('notes')
            ->assertMountedActionModalSee('Интересные места в Питере')
            ->assertMountedActionModalSee('Шаблон инструкции отбора')
            ->assertMountedActionModalSee('Шаблон редакционной инструкции');
    }
}
