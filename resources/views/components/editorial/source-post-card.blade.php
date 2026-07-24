@props([
    'sourcePost',
    'isPrimary' => false,
])

<article class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ $sourcePost->sourceChannel->title }}
                </h4>
                @if ($isPrimary)
                    <span class="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">
                        Основной источник
                    </span>
                @endif
            </div>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $sourcePost->posted_at->format('d.m.Y H:i') }}
            </p>
        </div>

        @if ($sourcePost->source_url)
            <a
                class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium text-primary-600 underline-offset-4 hover:bg-primary-50 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-primary-400 dark:hover:bg-primary-500/10"
                href="{{ $sourcePost->source_url }}"
                target="_blank"
                rel="noopener noreferrer"
            >
                Открыть в Telegram
            </a>
        @endif
    </div>

    <p class="mt-3 whitespace-pre-line text-sm leading-6 text-gray-700 dark:text-gray-200">
        {{ $sourcePost->text ?: 'Текст отсутствует — пост содержит только медиа.' }}
    </p>

    @if ($sourcePost->mediaAssets->isNotEmpty())
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
            @foreach ($sourcePost->mediaAssets as $asset)
                @php
                    $isVideo = in_array($asset->type, [\App\MediaType::Video, \App\MediaType::Animation], true);
                    $previewUrl = $asset->previewUrl();
                    $downloadUrl = $isVideo ? $asset->downloadUrl() : null;
                @endphp

                <figure class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-gray-950">
                    <div class="aspect-video bg-gray-100 dark:bg-gray-800">
                        @if ($isVideo && $downloadUrl)
                            <video class="h-full w-full object-cover" controls preload="metadata">
                                <source src="{{ $downloadUrl }}" type="{{ $asset->mime_type ?: 'video/mp4' }}">
                            </video>
                        @elseif ($previewUrl)
                            <img
                                class="h-full w-full object-cover"
                                src="{{ $previewUrl }}"
                                alt="{{ $isVideo ? 'Превью видео' : 'Фото' }} из источника {{ $sourcePost->sourceChannel->title }}"
                                loading="lazy"
                            >
                        @else
                            <div class="flex h-full items-center justify-center px-3 text-center text-xs text-gray-500 dark:text-gray-400">
                                @if ($asset->failed_at || $asset->preview_failed_at)
                                    Не удалось загрузить медиа
                                @elseif ($isVideo)
                                    Превью видео подготавливается
                                @else
                                    Фото подготавливается
                                @endif
                            </div>
                        @endif
                    </div>
                    <figcaption class="flex items-center justify-between gap-2 px-3 py-2 text-xs text-gray-600 dark:text-gray-300">
                        <span>{{ $isVideo ? ($downloadUrl ? 'Видео' : 'Видео · превью') : 'Фото' }}</span>
                        <span class="tabular-nums">#{{ $asset->id }}</span>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    @endif

    <dl class="mt-4 grid grid-cols-2 gap-2 border-t border-gray-200 pt-3 text-xs dark:border-white/10 sm:grid-cols-4">
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Просмотры</dt>
            <dd class="mt-1 font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($sourcePost->views, 0, ',', ' ') }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Реакции</dt>
            <dd class="mt-1 font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($sourcePost->reactions, 0, ',', ' ') }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Пересылки</dt>
            <dd class="mt-1 font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($sourcePost->forwards, 0, ',', ' ') }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Комментарии</dt>
            <dd class="mt-1 font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($sourcePost->comments, 0, ',', ' ') }}</dd>
        </div>
    </dl>
</article>
