<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportRequest;
use App\Http\Resources\ReportPaymentResource;
use App\Http\Resources\ReportProductResource;
use App\Http\Resources\ReportSaleResource;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    private function meta($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }

    public function summary(ReportRequest $request, ReportService $service): JsonResponse
    {
        [$start, $end] = $service->bounds($request->validated());

        return response()->json(['data' => $service->summary($request->validated()), 'period' => ['start_utc' => $start->toISOString(), 'end_exclusive_utc' => $end->toISOString(), 'timezone' => 'America/La_Paz']]);
    }

    public function sales(ReportRequest $request, ReportService $service): JsonResponse
    {
        $data = $request->validated();
        $page = $service->orders($data)->leftJoin('payments', 'payments.order_id', '=', 'orders.id')
            ->select('orders.uuid', 'orders.id as number', 'orders.created_at', 'orders.total_cents', 'orders.payment_status', 'orders.kitchen_status', 'payments.method as payment_method')
            ->orderByDesc('orders.id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn ($row) => (new ReportSaleResource($row))->resolve($request))->all(), 'meta' => $this->meta($page)]);
    }

    public function products(ReportRequest $request, ReportService $service): JsonResponse
    {
        $data = $request->validated();
        $page = $service->orders($data)->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->select('order_items.product_id', 'order_items.product_name')
            ->selectRaw('SUM(order_items.quantity) AS quantity, SUM(order_items.line_total_cents) AS total_cents')
            ->groupBy('order_items.product_id', 'order_items.product_name')->orderByDesc('quantity')->orderBy('order_items.product_id')
            ->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn ($row) => (new ReportProductResource($row))->resolve($request))->all(), 'meta' => $this->meta($page)]);
    }

    public function payments(ReportRequest $request, ReportService $service): JsonResponse
    {
        $data = $request->validated();
        $page = $service->orders($data)->join('payments', 'payments.order_id', '=', 'orders.id')
            ->select('payments.method', 'payments.status')->selectRaw('COUNT(*) AS count, SUM(payments.amount_cents) AS amount_cents')
            ->groupBy('payments.method', 'payments.status')->orderBy('payments.method')->orderBy('payments.status')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn ($row) => (new ReportPaymentResource($row))->resolve($request))->all(),
            'meta' => $this->meta($page), 'unpaid_count' => $service->summary($data)['unpaid_count']]);
    }
}
