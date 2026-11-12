<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItemComponent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function extras(): HasMany
    {
        return $this->hasMany(OrderComponentExtra::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(OrderComponentOption::class);
    }
}
