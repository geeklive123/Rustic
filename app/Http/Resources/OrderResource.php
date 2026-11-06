<?php

namespace App\Http\Resources;

use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $kitchen = $request->user()->role === Role::Kitchen;
        $data = [
            'uuid' => $this->uuid, 'number' => $this->id, 'customer_name' => $this->customer_name,
            'notes' => $this->notes, 'kitchen_status' => $this->kitchen_status,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
            'items' => $this->items->map(function ($item) use ($kitchen) {
                $line = ['product_id' => $item->product_id, 'product_name' => $item->product_name, 'quantity' => $item->quantity,
                    'notes' => $item->notes, 'extras' => $item->extras->map(function ($extra) use ($kitchen) {
                        $data = ['id' => $extra->extra_id, 'name' => $extra->name];
                        if (! $kitchen) {
                            $data['unit_price_cents'] = $extra->unit_price_cents;
                        }

                        return $data;
                    })->all(),
                    'options' => $item->options->map(fn ($option) => ['id' => $option->catalog_option_id, 'name' => $option->name])->all()];
                if (! $kitchen) {
                    $line['unit_price_cents'] = $item->unit_price_cents;
                    $line['line_total_cents'] = $item->line_total_cents;
                }

                return $line;
            })->all(),
        ];
        if (! $kitchen) {
            $data += ['operation_id' => $this->operation_id, 'device_id' => $this->device_id, 'seller_id' => $this->seller_id,
                'total_cents' => $this->total_cents, 'payment_status' => $this->payment_status,
                'payment' => $this->payment ? ['method' => $this->payment->method, 'status' => $this->payment->status, 'amount_cents' => $this->payment->amount_cents] + ($this->payment->method === 'QR_STATIC' ? ['confirmed_by' => $this->payment->confirmed_by, 'confirmed_at' => $this->payment->confirmed_at?->toISOString(), 'reference' => $this->payment->reference, 'operation_id' => $this->payment->confirmation_operation_id] : []) : null];
        }

        return $data;
    }
}
