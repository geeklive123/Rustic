<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\CashShift;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CashShiftService
{
    public function cashTotal(CashShift $shift, bool $lock = false): int
    {
        $query = Payment::where('cash_shift_id', $shift->id)->where('method', 'CASH')->where('status', 'cash_collected');

        return $lock ? (int) $query->lockForUpdate()->get(['amount_cents'])->sum('amount_cents') : (int) $query->sum('amount_cents');
    }

    public function open(User $user, array $data): array
    {
        try {
            return DB::transaction(function () use ($user, $data): array {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $prior = CashShift::where('open_operation_id', $data['operation_id'])->first();
                if ($prior) {
                    abort_unless((int) $prior->user_id === (int) $user->id && $prior->uuid === $data['uuid']
                        && $prior->opening_cents === (int) $data['opening_cents'], 409, 'Operacion de apertura reutilizada con otros datos.');

                    return [$prior, true];
                }
                abort_if(CashShift::where('active_user_id', $user->id)->exists(), 409, 'Ya existe un turno abierto.');
                $shift = CashShift::create(['uuid' => $data['uuid'], 'open_operation_id' => $data['operation_id'],
                    'user_id' => $user->id, 'active_user_id' => $user->id, 'opening_cents' => $data['opening_cents'], 'opened_at' => now('UTC')]);

                return [$shift, false];
            }, 3);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                abort(409, 'Identificador de turno u operacion ya utilizado.');
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
                $ownerId = (clone $query)->value('user_id');
                abort_if($ownerId === null, 404);
                User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
                $shift = $query->lockForUpdate()->firstOrFail();
                if ($shift->closed_at !== null) {
                    abort_unless($shift->close_operation_id === $data['operation_id'] && (int) $shift->closed_by === (int) $actor->id
                        && $shift->counted_cents === (int) $data['counted_cents'], 409, 'El turno ya esta cerrado.');

                    return [$shift, true];
                }
                abort_if(CashShift::where('close_operation_id', $data['operation_id'])->exists(), 409, 'Operacion de cierre ya utilizada.');
                $cash = $this->cashTotal($shift, true);
                $totals = app(CashTotals::class)->calculate($shift->opening_cents, $cash, (int) $data['counted_cents']);
                $shift->update(['active_user_id' => null, 'cash_sales_cents' => $cash, 'expected_cents' => $totals['expected_cents'],
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
