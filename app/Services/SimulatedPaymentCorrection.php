<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SimulatedPaymentCorrection
{
    public function correct(int $orderId, User $actor, array $data): void
    {
        abort_unless($actor->hasPermission('cash.manage'), 403);
        $reason = trim($data['reason']);
        $evidence = trim($data['evidence']);
        if ($reason === '' || $evidence === '') {
            throw ValidationException::withMessages(['reason' => 'Se requieren motivo y evidencia del efectivo recibido.']);
        }
        $operation = strtolower($data['operation_id']);
        $hash = hash('sha256', json_encode([$orderId, $operation, $reason, $evidence], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        try {
            DB::transaction(function () use ($orderId, $actor, $reason, $evidence, $operation, $hash) {
                $shiftId = Order::whereKey($orderId)->value('cash_shift_id');
                $shift = $shiftId === null ? null : CashShift::whereKey($shiftId)->lockForUpdate()->firstOrFail();
                $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
                $prior = DB::table('order_admin_corrections')->where('operation_id', $operation)->lockForUpdate()->first();
                if ($prior) {
                    abort_unless((int) $prior->order_id === $orderId && (int) $prior->user_id === (int) $actor->id && hash_equals($prior->request_hash, $hash), 409, 'Operacion reutilizada con otros datos.');

                    return;
                }
                abort_if($shift && $shift->closed_at !== null, 409, 'La caja esta cerrada; esta correccion requiere conciliacion posterior no disponible.');
                $payment = Payment::where('order_id', $orderId)->lockForUpdate()->first();
                abort_unless($payment && $payment->method === 'QR_SIMULATED' && $payment->status === 'simulated' && $order->payment_status === 'simulated'
                    && $payment->amount_cents === $order->total_cents && (int) ($payment->cash_shift_id ?? 0) === (int) ($order->cash_shift_id ?? 0), 409, 'El pago no es un QR simulado corregible.');
                $before = json_encode(['method' => $payment->method, 'status' => $payment->status, 'amount_cents' => $payment->amount_cents], JSON_THROW_ON_ERROR);
                $after = json_encode(['method' => 'CASH', 'status' => 'cash_collected', 'amount_cents' => $payment->amount_cents, 'evidence' => $evidence], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $payment->update(['method' => 'CASH', 'status' => 'cash_collected']);
                $order->update(['payment_status' => 'cash_collected']);
                DB::table('order_admin_corrections')->insert(['operation_id' => $operation, 'order_id' => $orderId, 'user_id' => $actor->id,
                    'request_hash' => $hash, 'action' => 'payment_reclassification', 'before_value' => $before, 'after_value' => $after, 'reason' => $reason, 'created_at' => now('UTC')]);
            }, 3);
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }
            $prior = DB::table('order_admin_corrections')->where('operation_id', $operation)->first();
            abort_unless($prior && (int) $prior->order_id === $orderId && (int) $prior->user_id === (int) $actor->id && hash_equals($prior->request_hash, $hash), 409, 'Operacion reutilizada con otros datos.');
        }
    }
}
