<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashMovementCompensation
{
    public function create(User $actor, int $shiftId, array $data): array
    {
        abort_unless($actor->hasPermission('cash.manage'), 403);
        $reason = trim($data['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'El motivo es obligatorio.']);
        }
        $uuid = strtolower($data['uuid']);
        $operation = strtolower($data['operation_id']);
        $originalId = (int) $data['original_movement_id'];
        $hash = hash('sha256', json_encode([$shiftId, $uuid, $operation, $originalId, $reason], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        try {
            return DB::transaction(function () use ($actor, $shiftId, $reason, $uuid, $operation, $originalId, $hash) {
                $shift = CashShift::whereKey($shiftId)->lockForUpdate()->firstOrFail();
                $prior = DB::table('cash_movements')->where('operation_id', $operation)->first();
                if ($prior) {
                    abort_unless((int) $prior->created_by === (int) $actor->id && (int) $prior->cash_shift_id === $shiftId && hash_equals($prior->request_hash, $hash), 409, 'Operacion reutilizada con otros datos.');

                    return [$prior, true];
                }
                abort_unless($shift->closed_at !== null && $shift->close_snapshot !== null, 409, 'Se requiere un cierre con instantanea.');
                $original = DB::table('cash_movements')->where('id', $originalId)->where('cash_shift_id', $shiftId)->lockForUpdate()->first();
                abort_unless($original && in_array($original->type, ['IN', 'OUT'], true), 409, 'Solo se pueden compensar entradas o salidas originales de este cierre.');
                abort_if(DB::table('cash_movements')->where('original_movement_id', $originalId)->exists(), 409, 'El movimiento ya fue compensado.');
                $amount = (int) $original->amount_cents;
                $effect = $original->type === 'IN' ? -$amount : $amount;
                $id = DB::table('cash_movements')->insertGetId(['uuid' => $uuid, 'operation_id' => $operation, 'request_hash' => $hash,
                    'cash_shift_id' => $shiftId, 'created_by' => $actor->id, 'authorized_by' => $actor->id, 'type' => 'COMPENSATION',
                    'amount_cents' => $amount, 'reason' => $reason, 'original_movement_id' => $originalId,
                    'cash_adjustment_cents' => $effect, 'created_at' => now('UTC')]);

                return [DB::table('cash_movements')->where('id', $id)->first(), false];
            }, 3);
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }
            $prior = DB::table('cash_movements')->where('operation_id', $operation)->first();
            abort_unless($prior && (int) $prior->created_by === (int) $actor->id && (int) $prior->cash_shift_id === $shiftId && hash_equals($prior->request_hash, $hash), 409, 'Identificador o movimiento ya utilizado.');

            return [$prior, true];
        }
    }
}
