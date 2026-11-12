<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\CashShift;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ManualQrPaymentService
{
    public function confirm(string $uuid, array $data, User $actor): array
    {
        try {
            return DB::transaction(function () use ($uuid, $data, $actor): array {
                // Lock the shift before the order, matching sale and close lock order.
                $shiftId = Order::where('uuid', $uuid)->value('cash_shift_id');
                if ($shiftId !== null) {
                    CashShift::whereKey($shiftId)->lockForUpdate()->firstOrFail();
                }
                $query = Order::where('uuid', $uuid);
                if ($actor->role !== Role::Administrator) {
                    $query->where('seller_id', $actor->id);
                }
                $order = $query->lockForUpdate()->firstOrFail();
                $payment = Payment::where('order_id', $order->id)->lockForUpdate()->first();
                abort_unless($payment && $payment->method === 'QR_STATIC', 409, 'El pedido no tiene un QR estatico pendiente.');
                $reference = $data['reference'] ?? null;
                if ($payment->status === 'qr_verified') {
                    abort_unless($payment->confirmation_operation_id === $data['operation_id']
                        && (int) $payment->confirmed_by === (int) $actor->id
                        && $payment->amount_cents === (int) $data['amount_cents']
                        && $payment->reference === $reference, 409, 'La confirmacion ya existe con otros datos.');

                    return [$order->load(['items.extras', 'items.options', 'payment']), false];
                }
                abort_unless($payment->status === 'qr_pending' && $order->payment_status === 'qr_pending'
                    && $payment->amount_cents === (int) $data['amount_cents']
                    && $order->total_cents === (int) $data['amount_cents'], 409, 'Estado o importe de pago incorrecto.');
                abort_if(Payment::where('confirmation_operation_id', $data['operation_id'])->exists(), 409, 'Operacion de confirmacion ya utilizada.');
                $payment->update(['status' => 'qr_verified', 'confirmation_operation_id' => $data['operation_id'],
                    'confirmed_by' => $actor->id, 'confirmed_at' => now('UTC'), 'reference' => $reference]);
                $order->update(['payment_status' => 'qr_verified']);

                return [$order->load(['items.extras', 'items.options', 'payment']), true];
            }, 3);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                abort(409, 'Operacion de confirmacion ya utilizada.');
            }
            throw $e;
        }
    }
}
