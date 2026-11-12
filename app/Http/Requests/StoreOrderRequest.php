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
            'items.*' => ['required', 'array:product_id,quantity,expected_unit_price_cents,extras,option_ids,notes,components,combo_revision,price_authorization_id'],
            'items.*.product_id' => ['required', 'integer', 'min:1'], 'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.expected_unit_price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'], 'items.*.extras' => ['present', 'array', 'max:100'],
            'items.*.extras.*' => ['required', 'array:extra_id,expected_unit_price_cents'],
            'items.*.extras.*.extra_id' => ['required', 'integer', 'min:1'],
            'items.*.extras.*.expected_unit_price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'items.*.price_authorization_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'items.*.combo_revision' => ['sometimes', 'required', 'integer', 'min:0'],
            'items.*.components' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:10'],
            'items.*.components.*' => ['required', 'array:component_id,name,extras,option_ids,notes'],
            'items.*.components.*.component_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'items.*.components.*.name' => ['sometimes', 'required', 'string', 'max:80', 'regex:/^[^\x00-\x1f\x7f]+$/u'],
            'items.*.components.*.notes' => ['nullable', 'string', 'max:200'],
            'items.*.components.*.extras' => ['present', 'array', 'list', 'max:20'],
            'items.*.components.*.extras.*' => ['required', 'array:extra_id,expected_unit_price_cents'],
            'items.*.components.*.extras.*.extra_id' => ['required', 'integer', 'min:1'],
            'items.*.components.*.extras.*.expected_unit_price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'items.*.components.*.option_ids' => ['present', 'array', 'list', 'max:20'],
            'items.*.components.*.option_ids.*' => ['required', 'integer', 'min:1'],
            'items.*.option_ids' => ['present', 'array', 'max:100'], 'items.*.option_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
