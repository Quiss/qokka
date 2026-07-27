@php
    $statistics = [
        [
            'label' => 'Публикации за 24 часа',
            'icon' => 'heroicon-m-document-text',
            'value' => \Illuminate\Support\Number::format((int) $record->posts_last_day_count, locale: 'ru'),
        ],
        [
            'label' => 'Просмотры за 24 часа',
            'icon' => 'heroicon-m-eye',
            'value' => \Illuminate\Support\Number::format((int) $record->views_last_day, locale: 'ru'),
        ],
        [
            'label' => 'Реакции за 24 часа',
            'icon' => 'heroicon-m-heart',
            'value' => \Illuminate\Support\Number::format((int) $record->reactions_last_day, locale: 'ru'),
        ],
        [
            'label' => 'Пересылки за 24 часа',
            'icon' => 'heroicon-m-paper-airplane',
            'value' => \Illuminate\Support\Number::format((int) $record->forwards_last_day, locale: 'ru'),
        ],
        [
            'label' => 'Комментарии за 24 часа',
            'icon' => 'heroicon-m-chat-bubble-left-right',
            'value' => \Illuminate\Support\Number::format((int) $record->comments_last_day, locale: 'ru'),
        ],
    ];
@endphp

<div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
    @foreach ($statistics as $statistic)
        <span
            class="inline-flex items-center gap-1 whitespace-nowrap text-sm font-medium tabular-nums text-gray-700 dark:text-gray-200"
            title="{{ $statistic['label'] }}"
            aria-label="{{ $statistic['label'] }}: {{ $statistic['value'] }}"
        >
            <x-filament::icon
                :icon="$statistic['icon']"
                class="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500"
                aria-hidden="true"
            />
            {{ $statistic['value'] }}
        </span>
    @endforeach
</div>
