<?php

namespace App\Models;

use App\AiOperation;
use Database\Factories\AiRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['subject_type', 'subject_id', 'operation', 'model', 'prompt_version', 'request_payload', 'response_payload', 'usage', 'cost_usd', 'status', 'error', 'started_at', 'completed_at'])]
class AiRun extends Model
{
    /** @use HasFactory<AiRunFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'running'];

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'operation' => AiOperation::class,
            'request_payload' => 'array',
            'response_payload' => 'array',
            'usage' => 'array',
            'cost_usd' => 'decimal:6',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
