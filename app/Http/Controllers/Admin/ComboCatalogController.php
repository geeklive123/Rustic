<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogComboComponent;
use App\Models\CatalogOption;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComboCatalogController extends Controller
{
    public function index(Product $product)
    {
        return view('admin.combo.index', ['product' => $product, 'components' => $product->comboComponents()->with(['extras', 'options'])->get()]);
    }

    public function create(Product $product)
    {
        return $this->form($product, null);
    }

    public function edit(Product $product, int $component)
    {
        return $this->form($product, $product->comboComponents()->with(['extras', 'options'])->findOrFail($component));
    }

    private function form(Product $product, ?CatalogComboComponent $component)
    {
        return view('admin.combo.form', ['product' => $product, 'component' => $component, 'extras' => $product->extras()->orderBy('name')->get(), 'options' => CatalogOption::orderBy('name')->get()]);
    }

    public function store(Request $request, Product $product)
    {
        return $this->save($request, $product, null);
    }

    public function update(Request $request, Product $product, int $component)
    {
        return $this->save($request, $product, $component);
    }

    private function save(Request $request, Product $product, ?int $id)
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:0'], 'name' => ['required', 'string', 'max:77', 'regex:/^[^\x00-\x1f\x7f]+$/u'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10'], 'customizable' => ['required', 'boolean'], 'is_active' => ['required', 'boolean'],
            'extra_ids' => ['sometimes', 'array', 'list', 'max:20'], 'extra_ids.*' => ['required', 'integer', Rule::exists('extras', 'id')],
            'option_ids' => ['sometimes', 'array', 'list', 'max:20'], 'option_ids.*' => ['required', 'integer', Rule::exists('catalog_options', 'id')],
        ]);
        DB::transaction(function () use ($product, $id, $data) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->combo_revision === (int) $data['revision'], 409, 'La composici?n cambi?; actualice la p?gina.');
            $row = $id ? $locked->comboComponents()->whereKey($id)->firstOrFail() : new CatalogComboComponent(['product_id' => $locked->id]);
            $extras = array_map('intval', $data['extra_ids'] ?? []);
            $options = array_map('intval', $data['option_ids'] ?? []);
            if (count($extras) !== count(array_unique($extras)) || count($options) !== count(array_unique($options))) {
                throw ValidationException::withMessages(['extra_ids' => 'No se permiten selecciones duplicadas.']);
            }
            $allowed = $locked->extras()->pluck('extras.id')->map(fn ($id) => (int) $id)->all();
            if (array_diff($extras, $allowed)) {
                throw ValidationException::withMessages(['extra_ids' => 'Cada extra debe estar permitido en el producto.']);
            }
            if (! (bool) $data['customizable'] && ($extras || $options)) {
                throw ValidationException::withMessages(['customizable' => 'Un componente sin personalizaci?n no admite extras ni opciones.']);
            }
            $otherQuantity = $locked->comboComponents()->where('is_active', true)->when($id, fn ($q) => $q->where('id', '!=', $id))->reorder()->sum('quantity');
            if ($otherQuantity + ((bool) $data['is_active'] ? (int) $data['quantity'] : 0) > 10) {
                throw ValidationException::withMessages(['quantity' => 'La composici?n activa admite hasta 10 unidades.']);
            }
            $row->fill(['name' => $data['name'], 'quantity' => $data['quantity'], 'customizable' => $data['customizable'], 'is_active' => $data['is_active']]);
            $row->save();
            $row->extras()->sync($extras);
            $row->options()->sync($options);
            $locked->combo_revision = (int) $locked->combo_revision + 1;
            $locked->save();
        }, 3);

        return redirect()->route('combo.index', $product)->with('success', 'Composici?n actualizada.');
    }
}
