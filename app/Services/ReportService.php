<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function bounds(array $data): array
    {
        $today = CarbonImmutable::now('America/La_Paz')->startOfDay();
        [$start, $end] = match ($data['period'] ?? 'today') {
            'yesterday' => [$today->subDay(), $today],
            'week' => [$today->startOfWeek(), $today->startOfWeek()->addWeek()],
            'month' => [$today->startOfMonth(), $today->startOfMonth()->addMonth()],
            'custom' => [CarbonImmutable::createFromFormat('!Y-m-d', $data['start_date'], 'America/La_Paz'), CarbonImmutable::createFromFormat('!Y-m-d', $data['end_date'], 'America/La_Paz')->addDay()],
            default => [$today, $today->addDay()],
        };

        return [$start->utc(), $end->utc()];
    }

    public function orders(array $data): Builder
    {
        [$start, $end] = $this->bounds($data);

        return DB::table('orders')->where('orders.created_at', '>=', $start)->where('orders.created_at', '<', $end);
    }

    public function summary(array $data): array
    {
        $row = $this->orders($data)->leftJoin('payments', 'payments.order_id', '=', 'orders.id')
            ->selectRaw("COUNT(*) AS order_count, COALESCE(SUM(orders.total_cents),0) AS total_cents,
                COALESCE(SUM(CASE WHEN payments.method = 'CASH' AND payments.status = 'cash_collected' THEN payments.amount_cents ELSE 0 END),0) AS cash_cents,
                COALESCE(SUM(CASE WHEN payments.method = 'QR_SIMULATED' AND payments.status = 'simulated' THEN 1 ELSE 0 END),0) AS simulated_qr_count,
                COALESCE(SUM(CASE WHEN payments.method = 'QR_SIMULATED' AND payments.status = 'simulated' THEN payments.amount_cents ELSE 0 END),0) AS simulated_qr_cents,
                COALESCE(SUM(CASE WHEN payments.method = 'QR_STATIC' AND payments.status = 'qr_verified' THEN 1 ELSE 0 END),0) AS verified_qr_count,
                COALESCE(SUM(CASE WHEN payments.method = 'QR_STATIC' AND payments.status = 'qr_verified' THEN payments.amount_cents ELSE 0 END),0) AS verified_qr_cents,
                COALESCE(SUM(CASE WHEN orders.payment_status IN ('unpaid', 'qr_pending') THEN 1 ELSE 0 END),0) AS unpaid_count")
            ->first();

        $summary = array_map('intval', (array) $row);
        $summary['qr_cents'] = $summary['simulated_qr_cents'] + $summary['verified_qr_cents'];
        $corrections = $this->orders($data)->join('order_admin_corrections', 'order_admin_corrections.order_id', '=', 'orders.id')
            ->where('order_admin_corrections.action', 'payment_reclassification')
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(orders.total_cents), 0) AS cents')->first();
        $summary['reclassified_payment_count'] = (int) $corrections->count;
        $summary['reclassified_payment_cents'] = (int) $corrections->cents;
        $summary['sellers'] = $this->orders($data)->join('users', 'users.id', '=', 'orders.seller_id')
            ->select('orders.seller_id', 'users.name')
            ->selectRaw('COUNT(*) AS order_count, COALESCE(SUM(orders.total_cents), 0) AS total_cents')
            ->groupBy('orders.seller_id', 'users.name')->orderBy('orders.seller_id')->get()
            ->map(fn ($seller) => ['seller_id' => (int) $seller->seller_id, 'name' => $seller->name,
                'order_count' => (int) $seller->order_count, 'total_cents' => (int) $seller->total_cents])->all();

        return $summary;
    }
}
