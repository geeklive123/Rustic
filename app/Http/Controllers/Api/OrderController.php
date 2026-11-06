<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    private function query(Request $request)
    {
        $query = Order::query();
        if ($request->user()->role === Role::Seller) {
            $query->where('seller_id', $request->user()->id);
        }

        return $query;
    }

    private function find(Request $request, string $uuid): Order
    {
        return $this->query($request)->with(['items.extras', 'items.options', 'payment'])->where('uuid', $uuid)->firstOrFail();
    }

    public function store(StoreOrderRequest $request, OrderService $service): JsonResponse
    {
        [$order, $created] = $service->create($request->validated(), $request->user());

        return response()->json(['data' => (new OrderResource($order))->resolve($request)], $created ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $orders = $this->query($request)->with(['items.extras', 'items.options', 'payment'])->orderByDesc('id')->paginate(25);

        return response()->json(['data' => $orders->getCollection()->map(fn ($order) => (new OrderResource($order))->resolve($request))->all(),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'per_page' => $orders->perPage(), 'total' => $orders->total()]]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return response()->json(['data' => (new OrderResource($this->find($request, $uuid)))->resolve($request)]);
    }

    public function syncStatus(Request $request, string $uuid): JsonResponse
    {
        $order = $this->find($request, $uuid);

        return response()->json(['data' => ['uuid' => $order->uuid, 'number' => $order->id, 'sync_status' => 'synced', 'updated_at' => $order->updated_at?->toISOString()]]);
    }
}
