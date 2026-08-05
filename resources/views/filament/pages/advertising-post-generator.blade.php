<x-filament-panels::page>
    <form wire:submit="generate">
        {{ $this->form }}

        <x-filament::button
            type="submit"
            icon="heroicon-o-sparkles"
            wire:loading.attr="disabled"
            wire:target="generate"
        >
            <span wire:loading.remove wire:target="generate">Сгенерировать</span>
            <span wire:loading wire:target="generate">Генерируем…</span>
        </x-filament::button>
    </form>
</x-filament-panels::page>
