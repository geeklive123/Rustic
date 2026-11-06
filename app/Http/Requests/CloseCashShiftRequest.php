<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseCashShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('cash.manage') ?? false;
    }

    public function rules(): array
    {
        return ['operation_id' => ['required', 'uuid'], 'counted_cents' => ['required', 'integer', 'min:0', 'max:10000000000']];
    }
}
