<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportSaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['uuid' => $this->uuid, 'number' => (int) $this->number,
            'created_at' => CarbonImmutable::parse($this->created_at, 'UTC')->toISOString(),
            'total_cents' => (int) $this->total_cents, 'payment_status' => $this->payment_status,
            'kitchen_status' => $this->kitchen_status, 'payment_method' => $this->payment_method];
    }
}
