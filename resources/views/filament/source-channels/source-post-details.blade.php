<div class="flex flex-col gap-5">
    <div class="grid gap-3 sm:grid-cols-3">
        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Медиа</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $record->mediaAssets->count() }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">фото и видео в посте</p>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Охват</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($record->views, 0, ',', ' ') }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">просмотров</p>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Вовлечение</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">
                {{ number_format($record->reactions + $record->forwards + $record->comments, 0, ',', ' ') }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">реакций, пересылок и комментариев</p>
        </section>
    </div>

    <x-editorial.source-post-card :source-post="$record" />
</div>
