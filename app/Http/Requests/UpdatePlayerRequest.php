<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $player = $this->route('player');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('players', 'name')->ignore($player),
            ],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_photo' => ['boolean'],
            'twitch_login' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_]{3,25}$/'],
        ];
    }
}
