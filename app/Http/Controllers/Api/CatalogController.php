<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogWriteRequest;
use App\Http\Resources\CatalogResource;
use App\Models\CatalogOption;
use App\Models\Category;
use App\Models\Extra;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    private function model(string $type): string
    {
        return match ($type) {
            'categories' => Category::class, 'products' => Product::class, 'extras' => Extra::class, 'options' => CatalogOption::class, default => abort(404)
        };
    }

    private function data($rows, Request $request): array
    {
        return $rows->map(fn ($row) => (new CatalogResource($row))->resolve($request))->all();
    }

    public function index(Request $request, string $type): JsonResponse
    {
        $model = $this->model($type);
        $query = $model::query();
        $includeInactive = $request->boolean('include_inactive') && $request->user()->hasPermission('menu.manage');
        if (! $includeInactive) {
            $query->where('is_active', true);
            if ($type === 'products') {
                $query->whereHas('category', fn ($q) => $q->where('is_active', true));
            }
        }
        if ($type === 'products') {
            $query->with(['extras' => fn ($q) => $includeInactive ? $q : $q->where('extras.is_active', true)]);
        }

        return response()->json(['data' => $this->data($query->orderBy('id')->get(), $request)]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $types = ['categories', 'products', 'extras', 'options'];
        $data = [];
        foreach ($types as $type) {
            $data[$type] = $this->index($request, $type)->getData(true)['data'];
        }

        return response()->json(['data' => $data]);
    }

    public function write(CatalogWriteRequest $request, string $type, ?int $id = null): JsonResponse
    {
        $model = $this->model($type);
        $data = $request->validated();
        $record = DB::transaction(function () use ($model, $type, $id, $data) {
            $row = $id ? $model::whereKey($id)->lockForUpdate()->firstOrFail() : new $model;
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
        });

        return response()->json(['data' => (new CatalogResource($record))->resolve($request)], $id ? 200 : 201);
    }
}
