<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'uuid' => $this->uuid, 'number' => $this->id, 'customer_name' => $this->customer_name,
            'notes' => $this->notes, 'kitchen_status' => $this->kitchen_status,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
            'items' => $this->items->map(function ($item) {
                $line = ['product_id' => $item->product_id, 'product_name' => $item->product_name, 'quantity' => $item->quantity,
                    'notes' => $item->kitchenNotes(), 'extras' => $item->extras->map(function ($extra) {
                        $data = ['id' => $extra->extra_id, 'name' => $extra->name];
                        $data['unit_price_cents'] = $extra->unit_price_cents;

                        return $data;
                    })->all(),
                    'options' => $item->options->map(fn ($option) => ['id' => $option->catalog_option_id, 'name' => $option->name])->all()];
                if ($item->components->isNotEmpty()) {
                    // Legacy Android reconstructs a line subtotal from unit price and flat extras.
                    $line['extras'] = $item->components->flatMap(fn ($component) => $component->extras->map(fn ($extra) => [
                        'id' => $extra->extra_id, 'name' => $extra->name, 'unit_price_cents' => (int) $extra->unit_price_cents,
                    ]))->values()->all();
                    $line['components'] = $item->components->map(fn ($component) => [
                        'position' => $component->position, 'component_id' => $component->catalog_component_id, 'name' => $component->name, 'notes' => $component->notes,
                        'extras' => $component->extras->map(fn ($extra) => ['id' => $extra->extra_id, 'name' => $extra->name, 'unit_price_cents' => (int) $extra->unit_price_cents])->all(),
                        'options' => $component->options->map(fn ($option) => ['id' => $option->catalog_option_id, 'name' => $option->name])->all(),
                    ])->all();
                }
                $line['unit_price_cents'] = $item->unit_price_cents;
                $line['line_total_cents'] = $item->line_total_cents;

                return $line;
            })->all(),
        ];
        $data += ['operation_id' => $this->operation_id, 'device_id' => $this->device_id, 'seller_id' => $this->seller_id,
            'cash_shift_id' => $this->cash_shift_id, 'total_cents' => $this->total_cents, 'payment_status' => $this->payment_status,
            'payment' => $this->payment ? ['method' => $this->payment->method, 'status' => $this->payment->status, 'amount_cents' => $this->payment->amount_cents] + ($this->payment->method === 'QR_STATIC' ? ['confirmed_by' => $this->payment->confirmed_by, 'confirmed_at' => $this->payment->confirmed_at?->toISOString(), 'reference' => $this->payment->reference, 'operation_id' => $this->payment->confirmation_operation_id] : []) : null];

        return $data;
    }
}
