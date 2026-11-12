<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\User;
use App\Services\CashShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CashController extends Controller
{
    private function amount(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function data(CashShift $shift, CashShiftService $service): array
    {
        return ['shift' => $shift, 'summary' => $shift->closed_at && $shift->close_snapshot === null ? null : $service->summary($shift), 'responsible' => User::find($shift->user_id)];
    }

    public function current(CashShiftService $service)
    {
        $shift = CashShift::whereNull('closed_at')->first();

        return view('admin.current', $shift ? $this->data($shift, $service) : ['shift' => null]);
    }

    public function openForm()
    {
        return view('admin.open', ['shift' => CashShift::whereNull('closed_at')->first(), 'uuid' => (string) Str::uuid(), 'operation' => (string) Str::uuid()]);
    }

    public function open(Request $request, CashShiftService $service)
    {
        $data = $request->validate(['uuid' => 'required|uuid', 'operation_id' => 'required|uuid', 'opening' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'], 'opening_qr' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/']]);
        [$shift] = $service->open($request->user(), ['uuid' => $data['uuid'], 'operation_id' => $data['operation_id'], 'opening_cents' => $this->amount($data['opening']), 'opening_qr_cents' => $this->amount($data['opening_qr'])]);

        return redirect()->route('cash.show', $shift)->with('success', 'Apertura registrada.');
    }

    public function show(CashShift $shift, CashShiftService $service)
    {
        return view('admin.current', $this->data($shift, $service));
    }

    public function history()
    {
        return view('admin.history', ['shifts' => CashShift::whereNotNull('closed_at')->withCount('orders')->orderByDesc('id')->paginate(20), 'users' => User::pluck('name', 'id')]);
    }

    public function orders(CashShift $shift)
    {
        return view('admin.orders', ['shift' => $shift, 'orders' => $shift->orders()->with(['items.extras', 'items.options', 'items.components.extras', 'items.components.options', 'payment'])->orderByDesc('id')->paginate(20), 'users' => User::all()->keyBy('id')]);
    }

    public function closeForm(CashShift $shift, CashShiftService $service)
    {
        abort_if($shift->closed_at !== null, 409, 'La caja ya est? cerrada.');

        return view('admin.close', $this->data($shift, $service) + ['operation' => (string) Str::uuid()]);
    }

    public function close(Request $request, CashShift $shift, CashShiftService $service)
    {
        $data = $request->validate(['operation_id' => 'required|uuid', 'counted' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'], 'note' => 'nullable|string|max:2000']);
        [$shift] = $service->close($request->user(), $shift->id, ['operation_id' => $data['operation_id'], 'counted_cents' => $this->amount($data['counted']), 'note' => $data['note'] ?? null]);

        return redirect()->route('cash.show', $shift)->with('success', 'Cierre registrado.');
    }
}
