<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenCashShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('cash.manage') ?? false;
    }

    public function rules(): array
    {
        return ['uuid' => ['required', 'uuid'], 'operation_id' => ['required', 'uuid'], 'opening_cents' => ['required', 'integer', 'min:0', 'max:10000000000'], 'opening_qr_cents' => ['sometimes', 'integer', 'min:0', 'max:10000000000']];
    }
}
