<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IngestTelegramUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'telegram_account_uuid' => ['required', 'uuid', 'exists:telegram_accounts,uuid'],
            'event_type' => ['required', 'string', 'in:message,edit,delete,metrics'],
            'peer_id' => ['required', 'integer'],
            'message_id' => ['required', 'integer'],
            'grouped_id' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'posted_at' => ['nullable', 'date'],
            'text' => ['nullable', 'string'],
            'entities' => ['nullable', 'array'],
            'metrics' => ['nullable', 'array'],
            'metrics.views' => ['nullable', 'integer', 'min:0'],
            'metrics.forwards' => ['nullable', 'integer', 'min:0'],
            'metrics.reactions' => ['nullable', 'integer', 'min:0'],
            'metrics.comments' => ['nullable', 'integer', 'min:0'],
            'metrics.reaction_breakdown' => ['nullable', 'array'],
            'media' => ['nullable', 'array', 'max:10'],
            'media.*.type' => ['required_with:media', 'string', 'in:photo,video,animation,document'],
            'media.*.external_id' => ['nullable', 'string', 'max:255'],
            'media.*.disk' => ['nullable', 'string', 'max:255'],
            'media.*.path' => ['nullable', 'string', 'max:2048'],
            'media.*.mime_type' => ['nullable', 'string', 'max:255'],
            'media.*.size_bytes' => ['nullable', 'integer', 'min:0'],
            'media.*.checksum' => ['nullable', 'string', 'size:64'],
            'media.*.metadata' => ['nullable', 'array'],
            'raw' => ['nullable', 'array'],
        ];
    }
}
