@php
    $conflicts = data_get($record->storyCandidate->score_breakdown, 'source_conflicts', []);
@endphp

<div class="flex flex-col gap-4">
    @if ($conflicts !== [])
        <section class="rounded-xl border border-danger-200 bg-danger-50 p-4 dark:border-danger-500/30 dark:bg-danger-500/10">
            <h3 class="text-sm font-semibold text-danger-900 dark:text-danger-100">Конфликты источников</h3>
            <div class="mt-3 flex flex-col gap-3">
                @foreach ($conflicts as $conflict)
                    <div>
                        <p class="text-sm font-medium text-danger-900 dark:text-danger-100">{{ $conflict['fact'] ?? 'Факт требует проверки' }}</p>
                        <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-danger-800 dark:text-danger-200">
                            @foreach (($conflict['variants'] ?? []) as $variant)
                                <li>{{ $variant }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <details class="group rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-4 py-3 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 dark:hover:bg-white/5 [&::-webkit-details-marker]:hidden">
            <div>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                    Источники кластера · {{ $record->storyCandidate->sourcePosts->count() }}
                </h3>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Откройте, чтобы сверить рерайт с исходными публикациями.
                </p>
            </div>
            <span class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 dark:text-primary-400">
                Показать
                <x-filament::icon
                    icon="heroicon-m-chevron-down"
                    class="h-4 w-4 transition group-open:rotate-180"
                />
            </span>
        </summary>

        <div class="flex flex-col gap-3 border-t border-gray-200 p-4 dark:border-white/10">
            @forelse ($record->storyCandidate->sourcePosts as $sourcePost)
                <x-editorial.source-post-card
                    :source-post="$sourcePost"
                    :is-primary="(bool) $sourcePost->pivot->is_primary"
                />
            @empty
                <p class="text-sm text-gray-600 dark:text-gray-300">У новости нет исходных публикаций.</p>
            @endforelse
        </div>
    </details>

    <details class="group rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-4 py-3 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 dark:hover:bg-white/5 [&::-webkit-details-marker]:hidden">
            <div>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                    История рерайтов · {{ $record->revisions->count() }}
                </h3>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Предыдущие версии и инструкции редактора.
                </p>
            </div>
            <span class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 dark:text-primary-400">
                Показать
                <x-filament::icon
                    icon="heroicon-m-chevron-down"
                    class="h-4 w-4 transition group-open:rotate-180"
                />
            </span>
        </summary>

        <div class="border-t border-gray-200 p-4 dark:border-white/10">
            @if ($record->revisions->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-300">Версий пока нет.</p>
            @else
                <ol class="grid gap-3 lg:grid-cols-2">
                    @foreach ($record->revisions as $revision)
                        <li class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                            <div class="flex items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <span>Версия {{ $revision->version }}</span>
                                <time datetime="{{ $revision->created_at->toIso8601String() }}">{{ $revision->created_at->format('d.m.Y H:i') }}</time>
                            </div>
                            @if ($revision->instruction)
                                <p class="mt-2 text-xs font-medium text-primary-700 dark:text-primary-300">Инструкция: {{ $revision->instruction }}</p>
                            @endif
                            <p class="mt-2 line-clamp-4 whitespace-pre-line text-sm leading-6 text-gray-700 dark:text-gray-200">{{ $revision->text }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </details>
</div>
