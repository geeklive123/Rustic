<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Extra extends Model
{
    protected $fillable = ['name', 'price_cents', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'price_cents' => 'integer'];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
