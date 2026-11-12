<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderNoteCorrection;
use App\Services\SimulatedPaymentCorrection;
use Illuminate\Http\Request;

class OrderCorrectionController extends Controller
{
    public function correctPayment(Request $request, Order $order, SimulatedPaymentCorrection $service)
    {
        $data = $request->validate(['operation_id' => 'required|uuid', 'reason' => 'required|string|max:500',
            'evidence' => 'required|string|max:500', 'confirmed' => 'required|accepted']);
        $service->correct($order->id, $request->user(), $data);

        return redirect()->route('admin.order', $order)->with('success', 'Metodo de pago corregido y auditado.');
    }

    public function update(Request $request, Order $order, OrderNoteCorrection $service)
    {
        $data = $request->validate(['operation_id' => ['required', 'uuid'], 'expected_status' => ['required', 'in:NEW,PREPARING'],
            'expected_note_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'], 'notes' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:500'], 'confirmed' => ['sometimes', 'accepted']]);
        $service->update($order->id, $request->user(), $data);

        return redirect()->route('admin.order', $order)->with('success', 'Observaci?n actualizada y registrada.');
    }
}
