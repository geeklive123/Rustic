<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmManualQrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('payments.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'operation_id' => ['required', 'uuid'],
            'amount_cents' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
