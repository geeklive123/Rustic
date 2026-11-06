<?php

namespace App\Http\Resources;

use App\Services\CashShiftService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();
        $cash = $this->closed_at ? $this->cash_sales_cents : (array_key_exists('live_cash_cents', $attributes)
            ? (int) $attributes['live_cash_cents'] : app(CashShiftService::class)->cashTotal($this->resource));

        return ['id' => $this->id, 'uuid' => $this->uuid, 'user_id' => $this->user_id,
            'status' => $this->closed_at ? 'CLOSED' : 'OPEN', 'opening_cents' => $this->opening_cents,
            'cash_sales_cents' => $cash, 'expected_cents' => $this->closed_at ? $this->expected_cents : $this->opening_cents + $cash,
            'counted_cents' => $this->counted_cents, 'difference_cents' => $this->difference_cents,
            'closed_by' => $this->closed_by, 'opened_at' => $this->opened_at?->toISOString(), 'closed_at' => $this->closed_at?->toISOString()];
    }
}
