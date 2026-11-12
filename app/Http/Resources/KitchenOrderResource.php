<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KitchenOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timezone = $request->input('timezone', 'America/La_Paz');

        return [
            'uuid' => $this->uuid, 'number' => $this->id, 'customer_name' => $this->customer_name,
            'notes' => $this->notes, 'kitchen_status' => $this->kitchen_status,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
            'created_at_display' => $this->created_at?->copy()->setTimezone($timezone)->toIso8601String(),
            'elapsed_seconds' => max(0, (int) $this->created_at->diffInSeconds(now('UTC'))),
            'preparing_at' => $this->preparing_at?->toISOString(), 'ready_at' => $this->ready_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'items' => $this->items->map(function ($item) {
                $line = [
                    'product_name' => $item->product_name, 'quantity' => $item->quantity, 'notes' => $item->kitchenNotes(),
                    'extras' => $item->extras->map(fn ($extra) => ['name' => $extra->name])->all(),
                    'options' => $item->options->map(fn ($option) => ['name' => $option->name])->all(),
                ];
                if ($item->components->isNotEmpty()) {
                    $line['components'] = $item->components->map(fn ($component) => [
                        'position' => $component->position, 'component_id' => $component->catalog_component_id, 'name' => $component->name, 'notes' => $component->notes,
                        'extras' => $component->extras->map(fn ($extra) => ['name' => $extra->name])->all(),
                        'options' => $component->options->map(fn ($option) => ['name' => $option->name])->all(),
                    ])->all();
                }

                return $line;
            })->all(),
        ];
    }
}
