<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OrderHistoryController extends Controller
{
    public function all(Request $request): View
    {
        return $this->listing($request, false);
    }

    public function unlinked(Request $request): View
    {
        return $this->listing($request, true);
    }

    private function listing(Request $request, bool $unlinked): View
    {
        $filters = $request->validate([
            'seller_id' => ['nullable', 'integer', 'min:1'],
            'method' => ['nullable', 'in:CASH,QR_SIMULATED,QR_STATIC,UNPAID'],
            'payment_status' => ['nullable', 'in:cash_collected,simulated,qr_verified,qr_pending,unpaid'],
            'kitchen_status' => ['nullable', 'in:NEW,PREPARING,READY,DELIVERED'],
            'shift' => ['nullable', 'regex:/^(none|[1-9][0-9]{0,18})$/'],
            'search' => ['nullable', 'string', 'regex:/^[0-9]{1,20}$/'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('start_date') ? ['after_or_equal:start_date'] : []),
        ]);
        $query = Order::query()->with(['items.extras', 'items.options', 'items.components.extras', 'items.components.options', 'payment']);
        if ($unlinked) {
            $query->whereNull('cash_shift_id');
        }
        if (! empty($filters['seller_id'])) {
            $query->where('seller_id', $filters['seller_id']);
        }
        if (! empty($filters['method'])) {
            if ($filters['method'] === 'UNPAID') {
                $query->whereDoesntHave('payment');
            } else {
                $query->whereHas('payment', fn ($payment) => $payment->where('method', $filters['method']));
            }
        }
        if (! empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }
        if (! empty($filters['kitchen_status'])) {
            $query->where('kitchen_status', $filters['kitchen_status']);
        }
        if (! empty($filters['shift'])) {
            $filters['shift'] === 'none' ? $query->whereNull('cash_shift_id') : $query->where('cash_shift_id', $filters['shift']);
        }
        if (! empty($filters['search'])) {
            $query->where('id', $filters['search']);
        }
        if (! empty($filters['start_date'])) {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filters['start_date'], 'America/La_Paz')->utc());
        }
        if (! empty($filters['end_date'])) {
            $query->where('created_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $filters['end_date'], 'America/La_Paz')->addDay()->utc());
        }
        $orders = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.order-history', ['orders' => $orders, 'users' => User::whereIn('id', $orders->pluck('seller_id'))->pluck('name', 'id'), 'unlinked' => $unlinked, 'filters' => $filters, 'sellers' => User::orderBy('name')->get(['id', 'name'])]);
    }

    public function show(Order $order): View
    {
        $order->load(['items.extras', 'items.options', 'items.components.extras', 'items.components.options', 'payment']);

        return view('admin.order-detail', ['order' => $order, 'seller' => User::find($order->seller_id), 'operation' => (string) Str::uuid(),
            'paymentOperation' => (string) Str::uuid(), 'paymentCorrectionAllowed' => ! $order->cash_shift_id || CashShift::whereKey($order->cash_shift_id)->whereNull('closed_at')->exists(),
            'corrections' => DB::table('order_admin_corrections')->leftJoin('users', 'users.id', '=', 'order_admin_corrections.user_id')->where('order_admin_corrections.order_id', $order->id)->select('order_admin_corrections.*', 'users.name as actor')->orderByDesc('order_admin_corrections.id')->get()]);
    }
}
