<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class DailyReportController extends Controller
{
    public function __invoke(ReportService $reports): View
    {
        $now = CarbonImmutable::now('America/La_Paz');
        $date = $now->format('Y-m-d');
        $period = ['period' => 'custom', 'start_date' => $date, 'end_date' => $date];
        $products = $reports->orders($period)->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->select('order_items.product_id', 'order_items.product_name')
            ->selectRaw('SUM(order_items.quantity) AS quantity')
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->orderByDesc('quantity')->orderBy('order_items.product_id')->orderBy('order_items.product_name')->limit(5)->get();
        $sales = $reports->orders($period)->leftJoin('payments', 'payments.order_id', '=', 'orders.id')
            ->select('orders.id', 'orders.created_at', 'orders.total_cents', 'orders.payment_status', 'payments.method')
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id')->limit(10)->get();

        return view('reports.daily', [
            'summary' => $reports->summary($period), 'products' => $products, 'sales' => $sales,
            'now' => $now,
        ]);
    }
}
