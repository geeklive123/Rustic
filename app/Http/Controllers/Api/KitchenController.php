<?php

namespace App\Http\Controllers\Api;

use App\Enums\KitchenStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\KitchenOrderResource;
use App\Models\Order;
use App\Services\KitchenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class KitchenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $iso = ['date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'];
        $data = $request->validate(['status' => ['sometimes', Rule::enum(KitchenStatus::class)],
            'updated_since' => ['sometimes', ...$iso], 'snapshot_until' => ['sometimes', ...$iso],
            'after_id' => ['sometimes', 'integer', 'min:0'],
            'timezone' => ['sometimes', 'timezone'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $until = isset($data['snapshot_until']) ? Carbon::parse($data['snapshot_until'])->utc() : now('UTC')->startOfSecond();
        $query = Order::with(['items.extras', 'items.options']);
        $query->where('updated_at', '<=', $until);
        if (isset($data['status'])) {
            $query->where('kitchen_status', $data['status']);
        }
        if (isset($data['updated_since'])) {
            $query->where('updated_at', '>=', Carbon::parse($data['updated_since'])->utc());
        }
        if (isset($data['after_id'])) {
            $query->where('id', '>', $data['after_id']);
        }
        $orders = $query->orderBy('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $orders->getCollection()->map(fn ($order) => (new KitchenOrderResource($order))->resolve($request))->all(),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'per_page' => $orders->perPage(), 'total' => $orders->total(),
                'snapshot_until' => $until->toIso8601String(), 'next_after_id' => $orders->hasMorePages() ? $orders->getCollection()->last()->id : null]]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $request->validate(['timezone' => ['sometimes', 'timezone']]);
        $order = Order::with(['items.extras', 'items.options'])->where('uuid', $uuid)->firstOrFail();

        return response()->json(['data' => (new KitchenOrderResource($order))->resolve($request)]);
    }

    public function update(Request $request, string $uuid, KitchenService $service): JsonResponse
    {
        $data = $request->validate(['expected_status' => ['required', Rule::enum(KitchenStatus::class)],
            'status' => ['required', Rule::enum(KitchenStatus::class)], 'operation_id' => ['required', 'uuid'],
            'timezone' => ['sometimes', 'timezone']]);
        [$order, $replayed] = $service->transition($uuid, $data['expected_status'], $data['status'], $data['operation_id'], $request->user());
        $order->load(['items.extras', 'items.options']);

        return response()->json(['data' => (new KitchenOrderResource($order))->resolve($request), 'replayed' => $replayed]);
    }

    public function status(Request $request, string $uuid): JsonResponse
    {
        $query = Order::where('uuid', $uuid);
        if ($request->user()->role !== Role::Administrator) {
            $query->where('seller_id', $request->user()->id);
        }
        $order = $query->firstOrFail();

        return response()->json(['data' => ['uuid' => $order->uuid, 'kitchen_status' => $order->kitchen_status,
            'updated_at' => $order->updated_at?->toISOString()]]);
    }
}
