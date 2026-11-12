<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\Extra;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id, 'name' => $this->name, 'is_active' => (bool) $this->is_active];
        if ($this->resource instanceof Product) {
            $this->resource->loadMissing(['comboComponents' => fn ($q) => $q->where('is_active', true)->with(['extras' => fn ($e) => $e->where('extras.is_active', true), 'options' => fn ($o) => $o->where('catalog_options.is_active', true)])]);
            $data['category_id'] = $this->category_id;
            $data['extra_ids'] = $this->extras->pluck('id')->values()->all();
            if ($this->comboComponents->isNotEmpty()) {
                $allowed = $this->extras->pluck('id')->all();
                $data['combo_revision'] = (int) $this->combo_revision;
                $data['combo_components'] = $this->comboComponents->map(fn ($component) => [
                    'id' => $component->id, 'name' => $component->name, 'quantity' => $component->quantity,
                    'customizable' => $component->customizable,
                    'extra_ids' => $component->extras->pluck('id')->filter(fn ($id) => in_array($id, $allowed, true))->values()->all(),
                    'option_ids' => $component->options->pluck('id')->values()->all(),
                ])->all();
            }
        }
        if (($this->resource instanceof Product || $this->resource instanceof Extra) && ($request->user()->role !== Role::Kitchen || $request->user()->hasPermission('orders.create'))) {
            $data['price_cents'] = $this->price_cents;
        }

        return $data;
    }
}
