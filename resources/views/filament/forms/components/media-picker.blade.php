@php
    $isDisabled = $field->isDisabled();
    $media = $assets
        ->map(function (\App\Models\MediaAsset $asset): array {
            $isVideo = $asset->type === \App\MediaType::Video;

            return [
                'id' => (string) $asset->id,
                'type' => $isVideo ? 'video' : 'photo',
                'type_label' => $isVideo ? 'Видео' : 'Фото',
                'source_label' => (string) $asset->getAttribute('source_label'),
                'source_url' => $asset->getAttribute('source_url'),
                'preview_url' => $asset->previewUrl(),
                'download_url' => $isVideo ? $asset->downloadUrl() : null,
                'mime_type' => $asset->mime_type ?: ($isVideo ? 'video/mp4' : 'image/jpeg'),
                'size_label' => $asset->size_bytes === null
                    ? 'Размер неизвестен'
                    : number_format($asset->size_bytes / 1024 / 1024, 1, ',', ' ').' МБ',
                'is_failed' => $asset->failed_at !== null,
            ];
        })
        ->values();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.$entangle(@js($getStatePath())),
            assets: @js($media),
            disabled: @js($isDisabled),
            init() {
                const allowedIds = this.assets.map((asset) => asset.id)
                this.state = (this.state ?? [])
                    .map((id) => String(id))
                    .filter((id) => allowedIds.includes(id))
            },
            isSelected(id) {
                return (this.state ?? []).includes(String(id))
            },
            asset(id) {
                return this.assets.find((asset) => asset.id === String(id))
            },
            remove(id) {
                if (this.disabled) {
                    return
                }

                this.state = this.state.filter((selectedId) => selectedId !== String(id))
            },
            reorder(ids) {
                if (this.disabled) {
                    return
                }

                this.state = ids.map((id) => String(id))
            },
        }"
        class="flex flex-col gap-5"
    >
        <section class="rounded-xl border border-primary-200 bg-primary-50/60 p-2.5 dark:border-primary-500/30 dark:bg-primary-500/10">
            <div class="flex items-center justify-between gap-2">
                <h4 class="text-xs font-semibold text-gray-950 dark:text-white">
                    Выбрано для публикации
                    <span class="font-normal text-gray-500 dark:text-gray-400">· порядок слева направо</span>
                </h4>
                <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold tabular-nums text-primary-700 shadow-sm dark:bg-gray-900 dark:text-primary-300">
                    <span x-text="(state ?? []).length"></span>/10
                </span>
            </div>

            <div
                x-show="(state ?? []).length === 0"
                class="mt-2 rounded-lg border border-dashed border-primary-300 px-3 py-2.5 text-center text-xs text-gray-600 dark:border-primary-500/40 dark:text-gray-300"
            >
                Медиа не выбрано — публикация уйдёт только с текстом.
            </div>

            <div
                x-show="(state ?? []).length > 0"
                x-sortable
                x-on:end.stop="reorder($event.target.sortable.toArray())"
                data-sortable-animation-duration="150"
                class="mt-2 flex gap-2 overflow-x-auto pb-1"
            >
                <template x-for="id in state" :key="id">
                    <article
                        x-bind:x-sortable-item="id"
                        class="flex w-56 shrink-0 items-center gap-2 rounded-lg border border-primary-200 bg-white p-1.5 shadow-sm dark:border-primary-500/30 dark:bg-gray-900"
                    >
                        <div class="h-9 w-12 shrink-0 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                            <template x-if="asset(id)?.preview_url">
                                <img
                                    class="h-full w-full object-cover"
                                    x-bind:src="asset(id).preview_url"
                                    x-bind:alt="asset(id).type_label"
                                >
                            </template>
                            <template x-if="! asset(id)?.preview_url">
                                <div class="flex h-full items-center justify-center text-xs font-medium text-gray-500 dark:text-gray-400">
                                    <span x-text="asset(id)?.type_label"></span>
                                </div>
                            </template>
                        </div>

                        <div class="min-w-0 grow">
                            <p class="truncate text-xs font-semibold text-gray-950 dark:text-white" x-text="asset(id)?.source_label"></p>
                            <p class="mt-0.5 text-[0.6875rem] text-gray-500 dark:text-gray-400">
                                <span x-text="asset(id)?.type_label"></span>
                                <span> · #</span><span x-text="id"></span>
                            </p>
                        </div>

                        @unless ($isDisabled)
                            <button
                                type="button"
                                x-sortable-handle
                                class="inline-flex min-h-8 min-w-8 cursor-grab items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 active:cursor-grabbing dark:hover:bg-white/10 dark:hover:text-white"
                                title="Перетащить"
                            >
                                <x-filament::icon icon="heroicon-m-bars-3" class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                x-on:click="remove(id)"
                                class="inline-flex min-h-8 min-w-8 items-center justify-center rounded-md text-danger-600 hover:bg-danger-50 focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-danger-400 dark:hover:bg-danger-500/10"
                                title="Убрать из публикации"
                            >
                                <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
                            </button>
                        @endunless
                    </article>
                </template>
            </div>
        </section>

        @if ($media->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600 dark:border-white/15 dark:text-gray-300">
                В источниках этой новости нет доступных фото или видео.
            </div>
        @else
            <section>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Все медиа из источников</h4>
                    <p
                        x-show="(state ?? []).length >= 10"
                        class="text-xs font-medium text-warning-700 dark:text-warning-300"
                    >
                        Достигнут лимит: сначала снимите выбор с одной карточки.
                    </p>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                    @foreach ($media as $item)
                        <label
                            class="group relative overflow-hidden rounded-xl border bg-white shadow-sm transition focus-within:outline-2 focus-within:outline-offset-2 dark:bg-gray-900"
                            x-bind:class="isSelected(@js($item['id']))
                                ? 'border-primary-500 ring-2 ring-primary-500/30'
                                : 'border-gray-200 hover:border-primary-300 dark:border-white/10 dark:hover:border-primary-500/50'"
                        >
                            <input
                                type="checkbox"
                                value="{{ $item['id'] }}"
                                x-model="state"
                                x-bind:disabled="disabled || (! isSelected(@js($item['id'])) && (state ?? []).length >= 10)"
                                class="sr-only"
                            >

                            <div class="h-24 bg-gray-100 dark:bg-gray-800">
                                @if ($item['type'] === 'video' && filled($item['download_url']))
                                    <video
                                        class="h-full w-full object-cover"
                                        controls
                                        preload="metadata"
                                        x-on:click.stop
                                    >
                                        <source src="{{ $item['download_url'] }}" type="{{ $item['mime_type'] }}">
                                    </video>
                                @elseif (filled($item['preview_url']))
                                    <img
                                        class="h-full w-full object-cover"
                                        src="{{ $item['preview_url'] }}"
                                        alt="{{ $item['type_label'] }} из источника {{ $item['source_label'] }}"
                                        loading="lazy"
                                    >
                                @else
                                    <div class="flex h-full items-center justify-center px-3 text-center text-xs text-gray-500 dark:text-gray-400">
                                        {{ $item['is_failed'] ? 'Не удалось загрузить медиа' : $item['type_label'].' подготавливается' }}
                                    </div>
                                @endif
                            </div>

                            <div class="flex items-start justify-between gap-2 p-2">
                                <div class="min-w-0">
                                    <p class="truncate text-xs font-semibold text-gray-950 dark:text-white">{{ $item['source_label'] }}</p>
                                    <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                                        {{ $item['type_label'] }} · #{{ $item['id'] }} · {{ $item['size_label'] }}
                                    </p>
                                </div>

                                <span
                                    x-show="isSelected(@js($item['id']))"
                                    class="shrink-0 rounded-full bg-primary-600 px-1.5 py-0.5 text-[0.6875rem] font-semibold text-white"
                                >
                                    ✓
                                </span>
                            </div>
                        </label>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-dynamic-component>
