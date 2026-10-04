<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStreamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'player_id' => ['required', 'integer', 'exists:players,id'],
            'title' => ['required', 'string', 'max:255'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'source' => ['nullable', 'string', 'max:255'],
            'video' => [
                'nullable',
                'file',
                'mimes:mp4,mkv,webm,mov',
                'mimetypes:video/mp4,video/x-matroska,video/webm,video/quicktime',
                'max:'.(config('streams.max_upload_mb') * 1024),
            ],
        ];
    }
}
