<?php

namespace App\Models;

use App\MediaType;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * @property int $id
 * @property MediaType $type
 * @property string|null $ingest_key
 * @property string|null $external_id
 * @property string $disk
 * @property string|null $path
 * @property string|null $preview_disk
 * @property string|null $preview_path
 * @property string|null $preview_mime_type
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property int $sort_order
 * @property array<string, mixed>|null $metadata
 */
#[Fillable(['mediable_type', 'mediable_id', 'source_message_id', 'origin_media_asset_id', 'ingest_key', 'external_id', 'type', 'disk', 'path', 'preview_disk', 'preview_path', 'preview_mime_type', 'mime_type', 'size_bytes', 'checksum', 'sort_order', 'metadata', 'downloaded_at', 'preview_downloaded_at', 'failed_at', 'preview_failed_at'])]
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    protected $attributes = ['disk' => 'local', 'sort_order' => 0];

    /** @return MorphTo<Model, $this> */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<SourceMessage, $this> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(SourceMessage::class);
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function originMediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'origin_media_asset_id');
    }

    public function previewUrl(): ?string
    {
        if (filled($this->preview_path)) {
            return $this->temporaryUrl($this->preview_disk ?: $this->disk, $this->preview_path);
        }

        if ($this->type !== MediaType::Photo || blank($this->path)) {
            return null;
        }

        return $this->temporaryUrl($this->disk, $this->path);
    }

    public function downloadUrl(): ?string
    {
        if (blank($this->path)) {
            return null;
        }

        return $this->temporaryUrl($this->disk, $this->path);
    }

    private function temporaryUrl(string $disk, string $path): ?string
    {
        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        try {
            return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(15));
        } catch (Throwable) {
            return Storage::disk($disk)->url($path);
        }
    }

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'metadata' => 'array',
            'downloaded_at' => 'datetime',
            'preview_downloaded_at' => 'datetime',
            'failed_at' => 'datetime',
            'preview_failed_at' => 'datetime',
        ];
    }
}
