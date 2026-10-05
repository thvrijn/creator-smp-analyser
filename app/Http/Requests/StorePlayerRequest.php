<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:players,name'],
            // `image` rejects SVG, which could carry scripts.
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'twitch_login' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_]{3,25}$/'],
        ];
    }
}
