<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\CashShift;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashShiftService
{
    public function cashTotal(CashShift $shift, bool $lock = false): int
    {
        $query = Payment::where('cash_shift_id', $shift->id)->where('method', 'CASH')->where('status', 'cash_collected');

        return $lock ? (int) $query->lockForUpdate()->get(['amount_cents'])->sum('amount_cents') : (int) $query->sum('amount_cents');
    }

    public function summary(CashShift $shift): array
    {
        if ($shift->closed_at !== null && $shift->close_snapshot !== null) {
            return $shift->close_snapshot;
        }
        $orders = Order::where('cash_shift_id', $shift->id)->with('payment')->get();
        $methods = ['CASH' => 0, 'QR_SIMULATED' => 0, 'QR_STATIC_VERIFIED' => 0, 'QR_STATIC_PENDING' => 0, 'UNPAID' => 0];
        $workers = [];
        foreach ($orders as $order) {
            $payment = $order->payment;
            $key = match (true) {
                ! $payment => 'UNPAID',
                $payment->method === 'QR_STATIC' && $payment->status === 'qr_verified' => 'QR_STATIC_VERIFIED',
                $payment->method === 'QR_STATIC' => 'QR_STATIC_PENDING',
                default => $payment->method,
            };
            $methods[$key] += $order->total_cents;
            $id = $order->seller_id;
            if (! isset($workers[$id])) {
                $workers[$id] = ['seller_id' => $id, 'order_count' => 0, 'total_cents' => 0];
            }
            $workers[$id]['order_count']++;
            $workers[$id]['total_cents'] += $order->total_cents;
        }

        $names = User::whereIn('id', array_keys($workers))->pluck('name', 'id');
        foreach ($workers as $id => &$worker) {
            $worker['name'] = $names[$id] ?? null;
        }
        unset($worker);

        return ['order_count' => $orders->count(), 'total_cents' => (int) $orders->sum('total_cents'),
            'methods_cents' => $methods, 'workers' => array_values($workers),
            'cash_sales_cents' => $this->cashTotal($shift), 'cash_outflows_cents' => 0];
    }

    public function open(User $user, array $data): array
    {
        try {
            return DB::transaction(function () use ($user, $data): array {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $prior = CashShift::where('open_operation_id', $data['operation_id'])->first();
                if ($prior) {
                    abort_unless((int) $prior->user_id === (int) $user->id && $prior->uuid === $data['uuid']
                        && $prior->opening_cents === (int) $data['opening_cents'] && $prior->opening_qr_cents === (int) ($data['opening_qr_cents'] ?? 0), 409, 'Operacion de apertura reutilizada con otros datos.');

                    return [$prior, true];
                }
                abort_if(CashShift::whereNull('closed_at')->exists(), 409, 'Ya existe un turno abierto.');
                $shift = CashShift::create(['uuid' => $data['uuid'], 'open_operation_id' => $data['operation_id'],
                    'user_id' => $user->id, 'active_user_id' => $user->id, 'active_global' => 1, 'opening_cents' => $data['opening_cents'], 'opening_qr_cents' => $data['opening_qr_cents'] ?? 0, 'opened_at' => now('UTC')]);

                return [$shift, false];
            }, 3);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                $prior = CashShift::where('open_operation_id', $data['operation_id'])->first();
                if ($prior && (int) $prior->user_id === (int) $user->id && $prior->uuid === $data['uuid']
                    && $prior->opening_cents === (int) $data['opening_cents'] && $prior->opening_qr_cents === (int) ($data['opening_qr_cents'] ?? 0)) {
                    return [$prior, true];
                }
                abort(409, 'Ya existe un turno abierto o el identificador ya fue utilizado.');
            }
            throw $e;
        }
    }

    public function close(User $actor, int $id, array $data): array
    {
        try {
            return DB::transaction(function () use ($actor, $id, $data): array {
                $query = CashShift::whereKey($id);
                if ($actor->role !== Role::Administrator) {
                    $query->where('user_id', $actor->id);
                }
                $shift = $query->lockForUpdate()->firstOrFail();
                if ($shift->closed_at !== null) {
                    abort_unless($shift->close_operation_id === $data['operation_id'] && (int) $shift->closed_by === (int) $actor->id
                        && $shift->counted_cents === (int) $data['counted_cents'] && $shift->close_note === ($data['note'] ?? null), 409, 'El turno ya esta cerrado.');

                    return [$shift, true];
                }
                abort_if(CashShift::where('close_operation_id', $data['operation_id'])->exists(), 409, 'Operacion de cierre ya utilizada.');
                $cash = $this->cashTotal($shift, true);
                $totals = app(CashTotals::class)->calculate($shift->opening_cents, $cash, (int) $data['counted_cents']);
                if ($totals['difference_cents'] !== 0 && trim($data['note'] ?? '') === '') {
                    throw ValidationException::withMessages(['note' => ['Se requiere una observacion cuando hay diferencia.']]);
                }
                $snapshot = $this->summary($shift);
                $shift->update(['close_snapshot' => $snapshot, 'close_note' => $data['note'] ?? null, 'active_user_id' => null, 'active_global' => null, 'cash_sales_cents' => $cash, 'expected_cents' => $totals['expected_cents'],
                    'counted_cents' => $data['counted_cents'], 'difference_cents' => $totals['difference_cents'],
                    'close_operation_id' => $data['operation_id'], 'closed_by' => $actor->id, 'closed_at' => now('UTC')]);

                return [$shift, false];
            }, 3);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                abort(409, 'Operacion de cierre ya utilizada.');
            }
            throw $e;
        }
    }
}
