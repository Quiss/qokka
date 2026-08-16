@php
    $isDisabled = $field->isDisabled();
    $media = $assets
        ->map(function (\App\Models\MediaAsset $asset) use ($maxBytes): array {
            $isVideo = $asset->type === \App\MediaType::Video;
            $isAnimation = $asset->type === \App\MediaType::Animation;
            $isSelectable = (bool) $asset->getAttribute('is_selectable');

            return [
                'id' => (string) $asset->getAttribute('selection_token'),
                'asset_id' => (string) $asset->id,
                'type' => match ($asset->type) {
                    \App\MediaType::Video => 'video',
                    \App\MediaType::Animation => 'animation',
                    default => 'photo',
                },
                'type_label' => match ($asset->type) {
                    \App\MediaType::Video => 'Видео',
                    \App\MediaType::Animation => 'GIF',
                    default => 'Фото',
                },
                'source_label' => (string) $asset->getAttribute('source_label'),
                'source_url' => $asset->getAttribute('source_url'),
                'preview_url' => $asset->previewUrl(),
                'download_url' => $isSelectable && ($isVideo || $isAnimation) ? $asset->downloadUrl() : null,
                'mime_type' => $asset->mime_type ?: match ($asset->type) {
                    \App\MediaType::Video, \App\MediaType::Animation => 'video/mp4',
                    default => 'image/jpeg',
                },
                'size_label' => $asset->size_bytes === null
                    ? 'Размер неизвестен'
                    : number_format($asset->size_bytes / 1024 / 1024, 1, ',', ' ').' МБ',
                'is_failed' => $asset->failed_at !== null,
                'is_selectable' => $isSelectable,
                'unavailable_reason' => $asset->getAttribute('unavailable_reason'),
                'is_custom' => (bool) $asset->getAttribute('is_custom'),
            ];
        })
        ->values();
    $uploadStatePath = \Illuminate\Support\Str::beforeLast($getStatePath(), '.').'.custom_media_uploads';
    $maxSizeLabel = \Illuminate\Support\Number::fileSize($maxBytes, maxPrecision: 2);
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.$entangle(@js($getStatePath())),
            assets: @js($media),
            disabled: @js($isDisabled),
            uploadStatePath: @js($uploadStatePath),
            maxBytes: @js($maxBytes),
            uploadedAssets: [],
            uploading: false,
            uploadProgress: 0,
            uploadError: null,
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
                return [...this.assets, ...this.uploadedAssets].find((asset) => asset.id === String(id))
            },
            canSelect(asset) {
                if (! asset.is_selectable) {
                    return false
                }

                const selectedAssets = (this.state ?? [])
                    .map((id) => this.asset(id))
                    .filter(Boolean)

                if (asset.type === 'animation') {
                    return selectedAssets.length === 0 || this.isSelected(asset.id)
                }

                return ! selectedAssets.some((selectedAsset) => selectedAsset.type === 'animation')
            },
            remove(id) {
                if (this.disabled) {
                    return
                }

                this.state = this.state.filter((selectedId) => selectedId !== String(id))

                if (String(id).startsWith('upload:')) {
                    const filename = String(id).slice(7)
                    const uploadedAsset = this.uploadedAssets.find((asset) => asset.id === String(id))

                    this.$wire.$removeUpload(this.uploadStatePath, filename, () => {
                        if (uploadedAsset?.preview_url) {
                            URL.revokeObjectURL(uploadedAsset.preview_url)
                        }

                        this.uploadedAssets = this.uploadedAssets.filter((asset) => asset.id !== String(id))
                    })
                }
            },
            reorder(ids) {
                if (this.disabled) {
                    return
                }

                this.state = ids.map((id) => String(id))
            },
            async chooseUploads(event) {
                const files = Array.from(event.target.files ?? [])
                event.target.value = ''
                this.uploadError = null

                if (this.disabled || files.length === 0) {
                    return
                }

                for (const file of files) {
                    if ((this.state ?? []).length >= 10) {
                        this.uploadError = 'Можно выбрать не более 10 файлов.'
                        break
                    }

                    const type = this.uploadType(file)

                    if (! type) {
                        this.uploadError = `Файл «${file.name}» имеет неподдерживаемый формат.`
                        continue
                    }

                    if (file.size > this.maxBytes) {
                        this.uploadError = `Файл «${file.name}» превышает лимит {{ $maxSizeLabel }}.`
                        continue
                    }

                    const draftAsset = {
                        id: '',
                        asset_id: '',
                        type,
                        type_label: type === 'photo' ? 'Фото' : (type === 'video' ? 'Видео' : 'GIF'),
                        source_label: 'Своё медиа',
                        source_url: null,
                        preview_url: URL.createObjectURL(file),
                        download_url: null,
                        mime_type: file.type,
                        size_label: this.formatSize(file.size),
                        is_failed: false,
                        is_selectable: true,
                        unavailable_reason: null,
                        is_custom: true,
                    }

                    if (! this.canSelect(draftAsset)) {
                        URL.revokeObjectURL(draftAsset.preview_url)
                        this.uploadError = 'GIF можно выбрать только отдельно от других медиа.'
                        continue
                    }

                    await this.uploadFile(file, draftAsset)
                }
            },
            uploadFile(file, draftAsset) {
                this.uploading = true
                this.uploadProgress = 0

                return new Promise((resolve) => {
                    this.$wire.$uploadMultiple(
                        this.uploadStatePath,
                        [file],
                        (filenames) => {
                            const filename = filenames[0]
                            draftAsset.id = `upload:${filename}`
                            this.uploadedAssets.push(draftAsset)
                            this.state = [...(this.state ?? []), draftAsset.id]
                            this.uploading = false
                            this.uploadProgress = 100
                            resolve()
                        },
                        () => {
                            URL.revokeObjectURL(draftAsset.preview_url)
                            this.uploading = false
                            this.uploadProgress = 0
                            this.uploadError = `Не удалось загрузить файл «${file.name}».`
                            resolve()
                        },
                        (progressEvent) => {
                            this.uploadProgress = progressEvent.detail?.progress ?? 0
                        },
                        () => {
                            URL.revokeObjectURL(draftAsset.preview_url)
                            this.uploading = false
                            this.uploadProgress = 0
                            resolve()
                        },
                    )
                })
            },
            uploadType(file) {
                if (['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                    return 'photo'
                }

                if (file.type === 'image/gif') {
                    return 'animation'
                }

                return file.type === 'video/mp4' ? 'video' : null
            },
            formatSize(bytes) {
                return `${(bytes / 1024 / 1024).toLocaleString('ru-RU', { maximumFractionDigits: 1 })} МБ`
            },
            destroy() {
                this.uploadedAssets.forEach((asset) => {
                    if (asset.preview_url) {
                        URL.revokeObjectURL(asset.preview_url)
                    }
                })
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

            @unless ($isDisabled)
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <label class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-primary-500 focus-within:outline-2 focus-within:outline-offset-2">
                        <x-filament::icon icon="heroicon-m-arrow-up-tray" class="h-4 w-4" />
                        Загрузить своё медиа
                        <input
                            type="file"
                            multiple
                            accept="image/jpeg,image/png,image/webp,image/gif,video/mp4"
                            x-on:change="chooseUploads($event)"
                            x-bind:disabled="uploading || (state ?? []).length >= 10"
                            class="sr-only"
                        >
                    </label>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        JPEG, PNG, WebP, GIF или MP4 · до {{ $maxSizeLabel }} на файл
                    </p>
                </div>

                <div x-show="uploading" class="mt-2 flex items-center gap-2 text-xs text-primary-700 dark:text-primary-300">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    <span>Загрузка: <span x-text="uploadProgress"></span>%</span>
                </div>

                <p
                    x-show="uploadError"
                    x-text="uploadError"
                    class="mt-2 text-xs font-medium text-danger-600 dark:text-danger-400"
                ></p>
            @endunless

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
                            <template x-if="asset(id)?.preview_url && ! asset(id)?.mime_type?.startsWith('video/')">
                                <img
                                    class="h-full w-full object-cover"
                                    x-bind:src="asset(id).preview_url"
                                    x-bind:alt="asset(id).type_label"
                                >
                            </template>
                            <template x-if="asset(id)?.preview_url && asset(id)?.mime_type?.startsWith('video/')">
                                <video
                                    class="h-full w-full object-cover"
                                    x-bind:src="asset(id).preview_url"
                                    muted
                                    playsinline
                                ></video>
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
                                <template x-if="asset(id)?.asset_id">
                                    <span> · #<span x-text="asset(id)?.asset_id"></span></span>
                                </template>
                                <span> · </span><span x-text="asset(id)?.size_label"></span>
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
                В источниках этой новости нет доступных фото, видео или GIF.
            </div>
        @else
            <section>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Доступные медиа</h4>
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
                                : (@js($item['is_selectable'])
                                    ? 'border-gray-200 hover:border-primary-300 dark:border-white/10 dark:hover:border-primary-500/50'
                                    : 'cursor-not-allowed border-gray-200 opacity-65 dark:border-white/10')"
                        >
                            <input
                                type="checkbox"
                                value="{{ $item['id'] }}"
                                x-model="state"
                                x-bind:disabled="disabled || ! canSelect(@js($item)) || (! isSelected(@js($item['id'])) && (state ?? []).length >= 10)"
                                class="sr-only"
                            >

                            <div class="h-24 bg-gray-100 dark:bg-gray-800">
                                @if ($item['type'] === 'animation' && filled($item['download_url']) && str_starts_with($item['mime_type'], 'image/'))
                                    <img
                                        class="h-full w-full object-cover"
                                        src="{{ $item['download_url'] }}"
                                        alt="{{ $item['type_label'] }} из источника {{ $item['source_label'] }}"
                                        loading="lazy"
                                    >
                                @elseif (in_array($item['type'], ['video', 'animation'], true) && filled($item['download_url']))
                                    <video
                                        class="h-full w-full object-cover"
                                        @if ($item['type'] === 'video') controls @else autoplay loop muted playsinline @endif
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
                                        {{ $item['type_label'] }} · #{{ $item['asset_id'] }} · {{ $item['size_label'] }}
                                    </p>
                                    @if ($item['unavailable_reason'])
                                        <p class="mt-1 text-xs font-medium text-danger-600 dark:text-danger-400">
                                            {{ $item['unavailable_reason'] }}
                                        </p>
                                    @endif
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
