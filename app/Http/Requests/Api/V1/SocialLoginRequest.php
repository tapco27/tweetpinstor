<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SocialLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Google / Apple ID token (JWT)
            'idToken' => ['required', 'string', 'min:50'],

            // Optional (Apple sometimes provides name only on first login)
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
