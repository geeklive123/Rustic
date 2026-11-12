<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Extra;
use App\Services\CatalogManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogWebController extends Controller
{
    private const LABELS = ['categories' => 'Categor?as', 'products' => 'Productos', 'extras' => 'Extras', 'options' => 'Opciones'];

    private function model(string $type): string
    {
        return app(CatalogManager::class)->model($type);
    }

    public function index(Request $request, string $type)
    {
        $model = $this->model($type);
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'category' => 'nullable|integer|min:1', 'status' => 'nullable|in:active,inactive']);
        $query = $model::query();
        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $filters['search']).'%');
        }
        if (! empty($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active');
        }
        if ($type === 'products') {
            $query->with(['category', 'extras']);
            if (! empty($filters['category'])) {
                $query->where('category_id', $filters['category']);
            }
        }
        if ($type === 'categories') {
            $query->with('products');
        }

        return view('admin.catalog.index', ['type' => $type, 'label' => self::LABELS[$type], 'rows' => $query->orderBy('name')->paginate(20)->withQueryString(), 'filters' => $filters, 'categories' => Category::orderBy('name')->get()]);
    }

    public function create(string $type)
    {
        $this->model($type);

        return $this->form($type, null);
    }

    public function edit(string $type, int $id)
    {
        $model = $this->model($type);
        $row = $model::findOrFail($id);
        if ($type === 'products') {
            $row->load('extras');
        }

        return $this->form($type, $row);
    }

    private function form(string $type, $row)
    {
        $changes = $row && in_array($type, ['products', 'extras'], true) ? DB::table('catalog_price_changes')->leftJoin('users', 'users.id', '=', 'catalog_price_changes.user_id')
            ->where('item_type', $type)->where('item_id', $row->id)->select('catalog_price_changes.*', 'users.name as actor')->orderByDesc('catalog_price_changes.id')->limit(20)->get() : collect();

        return view('admin.catalog.form', ['type' => $type, 'label' => self::LABELS[$type], 'row' => $row, 'categories' => Category::orderBy('name')->get(), 'extras' => Extra::orderBy('name')->get(), 'operation' => (string) Str::uuid(), 'changes' => $changes]);
    }

    public function store(Request $request, string $type)
    {
        return $this->write($request, $type, null);
    }

    public function update(Request $request, string $type, int $id)
    {
        return $this->write($request, $type, $id);
    }

    private function write(Request $request, string $type, ?int $id)
    {
        $this->model($type);
        $operation = $request->validate(['operation_id' => ['required', 'uuid']])['operation_id'];
        $hash = hash('sha256', json_encode([$type, $id, $request->except('_token')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        try {
            DB::transaction(function () use ($request, $type, $id, $operation, $hash) {
                DB::table('catalog_web_operations')->insert(['operation_id' => $operation, 'user_id' => $request->user()->id, 'request_hash' => $hash, 'item_type' => $type, 'created_at' => now('UTC')]);
                $table = match ($type) {
                    'categories' => 'categories', 'products' => 'products', 'extras' => 'extras', 'options' => 'catalog_options'
                };
                $rules = ['name' => ['required', 'string', 'max:255', Rule::unique($table, 'name')->ignore($id)], 'is_active' => ['required', 'boolean']];
                if ($type === 'products') {
                    $rules += ['category_id' => ['required', 'integer', Rule::exists('categories', 'id')], 'extra_ids' => ['sometimes', 'array', 'max:100'], 'extra_ids.*' => ['required', 'integer', 'distinct', Rule::exists('extras', 'id')]];
                }
                if (in_array($type, ['products', 'extras'], true)) {
                    $rules['price'] = ['required', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'];
                    if ($id) {
                        $rules['expected_price_cents'] = ['required', 'integer', 'min:0', 'max:100000000'];
                        $rules['confirm_price'] = ['sometimes', 'accepted'];
                    }
                }
                $data = $request->validate($rules);
                if (in_array($type, ['products', 'extras'], true)) {
                    [$whole, $fraction] = array_pad(explode('.', $data['price'], 2), 2, '');
                    $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
                    if ($cents > 100000000) {
                        throw ValidationException::withMessages(['price' => 'El precio supera el m?ximo permitido.']);
                    }
                    $data['price_cents'] = $cents;
                    unset($data['price']);
                }
                $expected = isset($data['expected_price_cents']) ? (int) $data['expected_price_cents'] : null;
                $confirmed = isset($data['confirm_price']);
                unset($data['expected_price_cents'], $data['confirm_price']);
                if ($type === 'products') {
                    $data['extra_ids'] = $data['extra_ids'] ?? [];
                }
                $row = app(CatalogManager::class)->save($type, $id, $data, $request->user(), $expected, $confirmed);
                DB::table('catalog_web_operations')->where('operation_id', $operation)->update(['item_id' => $row->id]);
            }, 3);
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }
            $prior = DB::table('catalog_web_operations')->where('operation_id', $operation)->first();
            if (! $prior) {
                throw ValidationException::withMessages(['name' => 'Ya existe un registro con esos datos.']);
            }
            abort_unless((int) $prior->user_id === (int) $request->user()->id && hash_equals($prior->request_hash, $hash), 409, 'Operaci?n reutilizada con otros datos.');
        }

        return redirect()->route('catalog.index', $type)->with('success', 'Cambios guardados.');
    }
}
