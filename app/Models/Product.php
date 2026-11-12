<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['category_id', 'name', 'price_cents', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'price_cents' => 'integer'];
    }

    public function comboComponents(): HasMany
    {
        return $this->hasMany(CatalogComboComponent::class)->orderBy('id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function extras(): BelongsToMany
    {
        return $this->belongsToMany(Extra::class);
    }
}
