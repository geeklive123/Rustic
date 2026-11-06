<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['product_id' => (int) $this->product_id, 'product_name' => $this->product_name,
            'quantity' => (int) $this->quantity, 'total_cents' => (int) $this->total_cents];
    }
}
