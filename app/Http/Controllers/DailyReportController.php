<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class DailyReportController extends Controller
{
    public function __invoke(Request $request, ReportService $reports): Response
    {
        $validator = Validator::make($request->query(), [
            'search' => ['nullable', 'string', 'regex:/^[0-9]{1,20}$/'],
            'detail' => ['nullable', 'string', 'regex:/^[0-9]{1,20}$/'],
            'page' => ['nullable', 'integer', 'min:1'],
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
        if (isset($input['search']) && $input['search'] !== '') {
            $filters['search'] = $input['search'];
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

        $historyQuery = $reports->orders($period)->leftJoin('payments', 'payments.order_id', '=', 'orders.id')
            ->select('orders.id', 'orders.created_at', 'orders.total_cents', 'orders.payment_status', 'orders.kitchen_status', 'payments.method');
        if (isset($filters['search'])) {
            $historyQuery->where('orders.id', '=', $filters['search']);
        }
        $history = $historyQuery->orderByDesc('orders.created_at')->orderByDesc('orders.id')->paginate(25)->appends($filters);

        $detail = null;
        $items = collect();
        if (isset($input['detail']) && $input['detail'] !== '') {
            $detailQuery = $reports->orders($period)->leftJoin('payments', 'payments.order_id', '=', 'orders.id')
                ->leftJoin('users', 'users.id', '=', 'orders.seller_id')
                ->select('orders.id', 'orders.created_at', 'orders.total_cents', 'orders.payment_status', 'orders.kitchen_status', 'payments.method', 'users.name as seller_name')
                ->where('orders.id', $input['detail']);
            if (isset($filters['search'])) {
                $detailQuery->where('orders.id', $filters['search']);
            }
            $detail = $detailQuery->first();
            if ($detail) {
                $items = DB::table('order_items')->where('order_id', $detail->id)
                    ->select('id', 'product_name', 'quantity', 'unit_price_cents', 'line_total_cents')->orderBy('id')->get();
                $itemIds = $items->pluck('id');
                $extras = DB::table('order_item_extras')->whereIn('order_item_id', $itemIds)
                    ->select('order_item_id', 'name', 'unit_price_cents')->orderBy('id')->get()->groupBy('order_item_id');
                $options = DB::table('order_item_options')->whereIn('order_item_id', $itemIds)
                    ->select('order_item_id', 'name')->orderBy('id')->get()->groupBy('order_item_id');
                foreach ($items as $item) {
                    $item->extras = $extras->get($item->id, collect());
                    $item->options = $options->get($item->id, collect());
                }
            }
        }

        return response()->view('reports.daily', [
            'summary' => $reports->summary($period), 'products' => $products, 'sales' => $sales,
            'history' => $history, 'detail' => $detail, 'items' => $items,
            'now' => $now, 'filters' => $filters, 'rangeStart' => $start->setTimezone('America/La_Paz'),
            'rangeEnd' => $end->setTimezone('America/La_Paz')->subDay(), 'errors' => $validator->errors(),
        ]);
    }
}
