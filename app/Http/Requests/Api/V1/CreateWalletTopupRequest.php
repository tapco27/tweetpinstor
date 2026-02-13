<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateWalletTopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'topup_uuid' => ['required', 'uuid'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'amount_minor' => ['required', 'integer', 'min:1'],

            'payer_full_name' => ['required', 'string', 'max:200'],
            'national_id' => ['required', 'string', 'max:50'],
            'phone' => ['required', 'string', 'max:30'],

            'receipt_note' => ['required', 'string', 'max:5000'],
            'receipt_image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge([
                'phone' => preg_replace('/\s+/', '', (string) $this->input('phone')),
            ]);
        }
    }
}
