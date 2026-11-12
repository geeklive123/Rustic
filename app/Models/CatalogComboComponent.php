<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CatalogComboComponent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'customizable' => 'boolean', 'is_active' => 'boolean'];
    }

    public function extras(): BelongsToMany
    {
        return $this->belongsToMany(Extra::class, 'combo_component_extra', 'component_id', 'extra_id');
    }

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(CatalogOption::class, 'combo_component_option', 'component_id', 'option_id');
    }
}
