<?php

namespace Tests\Unit;

use App\MediaType;
use App\Models\MediaAsset;
use App\Services\TelegramVideoPreparer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TelegramVideoPreparerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_inspects_original_video_and_generates_a_preview_without_transcoding(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('telegram/media/video.mp4', 'original-video');
        $asset = MediaAsset::factory()->create([
            'type' => MediaType::Video,
            'path' => 'telegram/media/video.mp4',
            'mime_type' => 'video/mp4',
        ]);
        Process::fake(function (PendingProcess $process) {
            if (($process->command[0] ?? null) === 'ffprobe') {
                return Process::result(json_encode([
                    'streams' => [[
                        'codec_type' => 'video',
                        'codec_name' => 'h264',
                        'width' => 1920,
                        'height' => 1080,
                        'duration' => '12.4',
                        'side_data_list' => [['rotation' => 90]],
                    ]],
                    'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'duration' => '12.4'],
                ], JSON_THROW_ON_ERROR));
            }

            File::put((string) end($process->command), 'generated-jpeg');

            return Process::result();
        });

        $prepared = app(TelegramVideoPreparer::class)->prepare($asset);

        $this->assertSame('original-video', Storage::disk('local')->get($asset->path));
        $this->assertSame(1080, $prepared->metadata['width']);
        $this->assertSame(1920, $prepared->metadata['height']);
        $this->assertSame(12, $prepared->metadata['duration']);
        $this->assertTrue($prepared->metadata['supports_streaming']);
        $this->assertNotNull($prepared->preview_path);
        Storage::disk('local')->assertExists($prepared->preview_path);
        Process::assertRanTimes(fn (PendingProcess $process): bool => in_array($process->command[0] ?? null, ['ffprobe', 'ffmpeg'], true), 2);
    }

    public function test_video_preparation_failures_do_not_block_the_original_video(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('telegram/media/video.mp4', 'original-video');
        $asset = MediaAsset::factory()->create([
            'type' => MediaType::Video,
            'path' => 'telegram/media/video.mp4',
            'mime_type' => 'video/mp4',
        ]);
        Process::fake(fn (): mixed => Process::result('', 'binary unavailable', 1));

        $prepared = app(TelegramVideoPreparer::class)->prepare($asset);

        $this->assertSame('original-video', Storage::disk('local')->get($prepared->path));
        $this->assertArrayHasKey('video_inspection_error', $prepared->metadata);
        $this->assertArrayHasKey('preview_generation_error', $prepared->metadata);
        $this->assertNull($prepared->preview_path);
    }
}
