<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\Order;
use App\Services\ReportService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(ReportService $reports): View
    {
        return view('admin.dashboard', [
            'summary' => $reports->summary(['period' => 'today']),
            'shift' => CashShift::whereNull('closed_at')->first(),
            'unlinkedCount' => Order::whereNull('cash_shift_id')->count(),
        ]);
    }
}
