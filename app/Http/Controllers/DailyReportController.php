<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class DailyReportController extends Controller
{
    public function __invoke(Request $request, ReportService $reports): Response
    {
        $validator = Validator::make($request->query(), [
            'period' => ['sometimes', 'required', Rule::in(['today', 'yesterday', 'week', 'month', 'custom'])],
            'start_date' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ], [
            'period.in' => 'Seleccione un período válido.',
            'period.required' => 'Seleccione un período.',
            'start_date.required_if' => 'Indique la fecha desde para el rango personalizado.',
            'end_date.required_if' => 'Indique la fecha hasta para el rango personalizado.',
            'start_date.date_format' => 'La fecha desde no es válida.',
            'end_date.date_format' => 'La fecha hasta no es válida.',
            'end_date.after_or_equal' => 'La fecha hasta debe ser igual o posterior a la fecha desde.',
        ]);

        $now = CarbonImmutable::now('America/La_Paz');
        if ($validator->fails()) {
            $submitted = $request->query();
            $safeFilters = [];
            foreach (['period', 'start_date', 'end_date'] as $key) {
                if (isset($submitted[$key]) && is_string($submitted[$key])) {
                    $safeFilters[$key] = $submitted[$key];
                }
            }

            return response()->view('reports.daily', [
                'errors' => $validator->errors(), 'now' => $now, 'summary' => null,
                'filters' => $safeFilters,
            ], 422);
        }

        $input = $validator->validated();
        $filters = ['period' => $input['period'] ?? 'today'];
        if ($filters['period'] === 'custom') {
            $filters['start_date'] = $input['start_date'];
            $filters['end_date'] = $input['end_date'];
        }
        [$start, $end] = $reports->bounds($filters);
        // Fix the interval once, including if midnight passes during the request.
        $period = [
            'period' => 'custom',
            'start_date' => $start->setTimezone('America/La_Paz')->format('Y-m-d'),
            'end_date' => $end->setTimezone('America/La_Paz')->subDay()->format('Y-m-d'),
        ];
        $products = $reports->orders($period)->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->select('order_items.product_id', 'order_items.product_name')
            ->selectRaw('SUM(order_items.quantity) AS quantity')
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->orderByDesc('quantity')->orderBy('order_items.product_id')->orderBy('order_items.product_name')->limit(5)->get();
        $sales = $reports->orders($period)->leftJoin('payments', 'payments.order_id', '=', 'orders.id')
            ->select('orders.id', 'orders.created_at', 'orders.total_cents', 'orders.payment_status', 'payments.method')
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id')->limit(10)->get();

        return response()->view('reports.daily', [
            'summary' => $reports->summary($period), 'products' => $products, 'sales' => $sales,
            'now' => $now, 'filters' => $filters, 'rangeStart' => $start->setTimezone('America/La_Paz'),
            'rangeEnd' => $end->setTimezone('America/La_Paz')->subDay(), 'errors' => $validator->errors(),
        ]);
    }
}
