<?php

namespace App\Services;

use App\Models\CatalogOption;
use App\Models\Category;
use App\Models\Extra;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogManager
{
    public function model(string $type): string
    {
        return match ($type) {
            'categories' => Category::class, 'products' => Product::class,
            'extras' => Extra::class, 'options' => CatalogOption::class,
            default => abort(404),
        };
    }

    public function save(string $type, ?int $id, array $data, User $actor, ?int $expectedPrice = null, bool $confirmed = false): Model
    {
        $model = $this->model($type);

        return DB::transaction(function () use ($model, $type, $id, $data, $actor, $expectedPrice, $confirmed) {
            $row = $id ? $model::whereKey($id)->lockForUpdate()->firstOrFail() : new $model;
            $priceChange = $id && in_array($type, ['products', 'extras'], true) && array_key_exists('price_cents', $data) && (int) $data['price_cents'] !== $row->price_cents;
            if ($expectedPrice !== null && $id && $row->price_cents !== $expectedPrice) {
                abort(409, 'El precio cambi? desde que se abri? el formulario. Actualice y revise los datos.');
            }
            if ($expectedPrice !== null && $priceChange && ! $confirmed) {
                throw ValidationException::withMessages(['confirm_price' => 'Confirme el cambio de precio.']);
            }
            if ($priceChange) {
                DB::table('catalog_price_changes')->insert(['item_type' => $type, 'item_id' => $row->id, 'user_id' => $actor->id,
                    'old_cents' => $row->price_cents, 'new_cents' => $data['price_cents'], 'created_at' => now('UTC')]);
            }
            $row->fill(collect($data)->except('extra_ids')->all());
            $row->save();
            $row->refresh();
            if ($type === 'products' && array_key_exists('extra_ids', $data)) {
                $row->extras()->sync($data['extra_ids']);
            }
            if ($type === 'products') {
                $row->load('extras');
            }

            return $row;
        }, 3);
    }
}
