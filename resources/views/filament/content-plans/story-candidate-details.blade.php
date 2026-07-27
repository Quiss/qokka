@php
    $conflicts = data_get($record->score_breakdown, 'source_conflicts', []);
    $scoreLabels = [
        'freshness' => 'Свежесть',
        'reach' => 'Охват',
        'engagement' => 'Вовлечение',
        'source_weight' => 'Вес источника',
        'value' => 'Практическая ценность',
        'originality' => 'Оригинальность',
    ];
@endphp

<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.68fr)]">
    <div class="flex min-w-0 flex-col gap-6">
        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Суть новости</h3>
            <p class="mt-3 whitespace-pre-line text-sm leading-6 text-gray-700 dark:text-gray-200">
                {{ $record->summary ?: 'Краткое описание отсутствует.' }}
            </p>

            @if ($record->ai_reason)
                <div class="mt-4 rounded-lg bg-primary-50 p-3 dark:bg-primary-500/10">
                    <p class="text-xs font-semibold uppercase tracking-wide text-primary-700 dark:text-primary-300">Почему ИИ выбрал новость</p>
                    <p class="mt-2 text-sm leading-6 text-primary-950 dark:text-primary-100">{{ $record->ai_reason }}</p>
                </div>
            @endif
        </section>

        <section>
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                    Источники кластера
                </h3>
                <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $record->sourcePosts->count() }}</span>
            </div>
            <div class="mt-3 flex flex-col gap-3">
                @forelse ($record->sourcePosts as $sourcePost)
                    <x-editorial.source-post-card
                        :source-post="$sourcePost"
                        :is-primary="(bool) $sourcePost->pivot->is_primary"
                    />
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600 dark:border-white/15 dark:text-gray-300">
                        У кандидата нет исходных постов.
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    <aside class="flex min-w-0 flex-col gap-6">
        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Оценка ИИ</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">
                        {{ number_format((float) $record->score, 0) }}
                        <span class="text-base font-normal text-gray-500 dark:text-gray-400">/ 100</span>
                    </p>
                </div>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                    {{ $record->sourcePosts->count() }} источников
                </span>
            </div>

            <dl class="mt-4 flex flex-col gap-3 border-t border-gray-200 pt-4 dark:border-white/10">
                @foreach ($scoreLabels as $key => $label)
                    @if (data_get($record->score_breakdown, $key) !== null)
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-gray-600 dark:text-gray-300">{{ $label }}</dt>
                            <dd class="font-semibold tabular-nums text-gray-950 dark:text-white">
                                {{ number_format((float) data_get($record->score_breakdown, $key), 1, ',', ' ') }}
                            </dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        </section>

        @if (($record->risk_flags ?? []) !== [])
            <section class="rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
                <h3 class="text-sm font-semibold text-warning-900 dark:text-warning-100">Риски</h3>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($record->risk_flags as $risk)
                        <span class="rounded-full bg-warning-100 px-2.5 py-1 text-xs font-medium text-warning-900 dark:bg-warning-500/20 dark:text-warning-100">
                            {{ \App\RiskFlagLabels::label($risk) }}
                        </span>
                    @endforeach
                </div>
            </section>
        @endif

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
    </aside>
</div>
