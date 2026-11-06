<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'confirmed_at' => 'immutable_datetime'];
    }
}
