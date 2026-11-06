<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['method' => $this->method, 'status' => $this->status, 'count' => (int) $this->count,
            'amount_cents' => (int) $this->amount_cents, 'is_actual_cash' => $this->method === 'CASH' && $this->status === 'cash_collected'];
    }
}
