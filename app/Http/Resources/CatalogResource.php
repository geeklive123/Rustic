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
            $data['category_id'] = $this->category_id;
            $data['extra_ids'] = $this->extras->pluck('id')->values()->all();
        }
        if (($this->resource instanceof Product || $this->resource instanceof Extra) && $request->user()->role !== Role::Kitchen) {
            $data['price_cents'] = $this->price_cents;
        }

        return $data;
    }
}
