<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('orders.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'], 'operation_id' => ['required', 'uuid'], 'device_id' => ['required', 'string', 'max:100'],
            'customer_name' => ['required', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['required', Rule::in(['CASH', 'QR_SIMULATED', 'QR_STATIC', 'UNPAID'])],
            'cash_shift_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'expected_total_cents' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_id,quantity,expected_unit_price_cents,extras,option_ids,notes'],
            'items.*.product_id' => ['required', 'integer', 'min:1'], 'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.expected_unit_price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'], 'items.*.extras' => ['present', 'array', 'max:100'],
            'items.*.extras.*' => ['required', 'array:extra_id,expected_unit_price_cents'],
            'items.*.extras.*.extra_id' => ['required', 'integer', 'min:1'],
            'items.*.extras.*.expected_unit_price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'items.*.option_ids' => ['present', 'array', 'max:100'], 'items.*.option_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
