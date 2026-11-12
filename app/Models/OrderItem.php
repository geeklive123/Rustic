<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_cents' => 'integer', 'line_total_cents' => 'integer'];
    }

    public function components(): HasMany
    {
        return $this->hasMany(OrderItemComponent::class)->orderBy('position');
    }

    public function kitchenNotes(): ?string
    {
        if ($this->components->isEmpty()) {
            return $this->notes;
        }
        $lines = [];
        if ($this->notes) {
            $lines[] = $this->notes;
        }
        foreach ($this->components as $component) {
            $details = [];
            if ($component->extras->isNotEmpty()) {
                $details[] = 'Extras: '.$component->extras->pluck('name')->join(', ');
            }
            if ($component->options->isNotEmpty()) {
                $details[] = 'Opciones: '.$component->options->pluck('name')->join(', ');
            }
            if ($component->notes) {
                $details[] = $component->notes;
            }
            $lines[] = $component->position.'. '.$component->name.($details ? ' ? '.implode(' ? ', $details) : '');
        }

        return implode("\n", $lines);
    }

    public function extras(): HasMany
    {
        return $this->hasMany(OrderItemExtra::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class);
    }
}
