<?php

namespace App\Filament\Resources\Publications\Actions;

use Filament\Actions\Action;
use Filament\Support\Enums\Width;

class PublicationNotesAction
{
    public static function make(): Action
    {
        return Action::make('notes')
            ->label('Заметки')
            ->icon('heroicon-o-book-open')
            ->color('gray')
            ->modalHeading('Как настроить канал')
            ->modalContent(view('filament.publications.notes'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->modalWidth(Width::SevenExtraLarge);
    }
}
