<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Services\CashMovementCompensation;
use App\Services\CashMovementService;
use App\Services\CashShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CashMovementController extends Controller
{
    private function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public function index(CashShift $shift, CashShiftService $service)
    {
        return view('admin.movements', ['shift' => $shift, 'summary' => $shift->closed_at && ! $shift->close_snapshot ? null : $service->summary($shift),
            'movements' => DB::table('cash_movements as m')->join('users as u', 'u.id', '=', 'm.created_by')->where('m.cash_shift_id', $shift->id)->select('m.*', 'u.name as creator')->orderByDesc('m.id')->paginate(25),
            'adjustment' => (int) DB::table('cash_movements')->where('cash_shift_id', $shift->id)->where('type', 'COMPENSATION')->sum('cash_adjustment_cents'),
            'uuid' => (string) Str::uuid(), 'operation' => (string) Str::uuid()]);
    }

    public function store(Request $request, CashShift $shift, CashMovementService $service)
    {
        $data = $request->validate(['uuid' => 'required|uuid', 'operation_id' => 'required|uuid', 'type' => 'required|in:IN,OUT,TRANSFER',
            'amount' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'], 'reason' => 'required|string|max:500',
            'bank_amount' => ['required_if:type,TRANSFER', 'nullable', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'],
            'bank_reference' => 'required_if:type,TRANSFER|nullable|string|max:255', 'verification_evidence' => 'required_if:type,TRANSFER|nullable|string|max:500',
            'verified' => $request->input('type') === 'TRANSFER' ? 'required|accepted' : 'nullable', 'payment_id' => 'nullable|integer|min:1']);
        $input = ['uuid' => $data['uuid'], 'operation_id' => $data['operation_id'], 'type' => $data['type'], 'amount_cents' => $this->cents($data['amount']), 'reason' => $data['reason']];
        if ($data['type'] === 'TRANSFER') {
            $input += ['bank_amount_cents' => $this->cents($data['bank_amount']), 'bank_reference' => $data['bank_reference'],
                'verification_evidence' => $data['verification_evidence'], 'verified' => true, 'payment_id' => isset($data['payment_id']) ? (int) $data['payment_id'] : null];
        }
        $service->create($request->user(), $shift->id, $input);

        return redirect()->route('cash.movements', $shift)->with('success', 'Movimiento registrado.');
    }

    public function compensate(Request $request, CashShift $shift, CashMovementCompensation $service)
    {
        $data = $request->validate(['uuid' => 'required|uuid', 'operation_id' => 'required|uuid', 'original_movement_id' => 'required|integer|min:1',
            'reason' => 'required|string|max:500', 'confirmed' => 'required|accepted']);
        $service->create($request->user(), $shift->id, $data);

        return redirect()->route('cash.movements', $shift)->with('success', 'Compensacion registrada; el cierre original permanece intacto.');
    }
}
