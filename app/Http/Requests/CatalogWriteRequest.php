<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('menu.manage') ?? false;
    }

    public function rules(): array
    {
        $type = $this->route('type');
        $table = match ($type) {
            'categories' => 'categories', 'products' => 'products', 'extras' => 'extras', 'options' => 'catalog_options', default => abort(404)
        };
        $presence = $this->isMethod('post') ? 'required' : 'sometimes';
        $rules = [
            'name' => [$presence, 'required', 'string', 'max:255', Rule::unique($table, 'name')->ignore($this->route('id'))],
            'is_active' => ['sometimes', 'boolean'],
        ];
        if ($type === 'products') {
            $rules['category_id'] = [$presence, 'required', 'integer', Rule::exists('categories', 'id')];
            $rules['extra_ids'] = ['sometimes', 'array', 'max:100'];
            $rules['extra_ids.*'] = ['required', 'integer', 'distinct', Rule::exists('extras', 'id')];
        }
        if (in_array($type, ['products', 'extras'], true)) {
            $rules['price_cents'] = [$presence, 'required', 'integer', 'min:0', 'max:100000000'];
        }

        return $rules;
    }
}
