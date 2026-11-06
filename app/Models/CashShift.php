<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashShift extends Model
{
    protected $guarded = [];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    protected function casts(): array
    {
        return ['opening_cents' => 'integer', 'cash_sales_cents' => 'integer', 'expected_cents' => 'integer',
            'counted_cents' => 'integer', 'difference_cents' => 'integer', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }
}
