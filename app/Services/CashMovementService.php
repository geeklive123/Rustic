<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashMovementService
{
    public static function reference(string $reference): string
    {
        return mb_strtolower(trim($reference));
    }

    public function create(User $actor, int $shiftId, array $data): array
    {
        abort_unless($actor->hasPermission('cash.manage'), 403);
        $data['uuid'] = strtolower($data['uuid']);
        $data['operation_id'] = strtolower($data['operation_id']);
        $data['reason'] = trim($data['reason']);
        $data['bank_reference'] = isset($data['bank_reference']) ? trim($data['bank_reference']) : null;
        $data['verification_evidence'] = isset($data['verification_evidence']) ? trim($data['verification_evidence']) : null;
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        try {
            return DB::transaction(function () use ($actor, $shiftId, $data, $hash) {
                $shift = CashShift::whereKey($shiftId)->lockForUpdate()->firstOrFail();
                $prior = DB::table('cash_movements')->where('operation_id', $data['operation_id'])->first();
                if ($prior) {
                    abort_unless((int) $prior->cash_shift_id === $shiftId && (int) $prior->created_by === (int) $actor->id && hash_equals($prior->request_hash, $hash), 409, 'Operacion reutilizada con otros datos.');

                    return [$prior, true];
                }
                abort_if($shift->closed_at !== null, 409, 'La caja esta cerrada.');
                if ($data['reason'] === '' || (int) $data['amount_cents'] <= 0 || ! in_array($data['type'], ['IN', 'OUT', 'TRANSFER'], true)) {
                    throw ValidationException::withMessages(['amount' => 'Movimiento o importe invalido.']);
                }
                $bankAmount = null;
                $paymentId = null;
                $referenceHash = null;
                if ($data['type'] === 'TRANSFER') {
                    if (empty($data['verified']) || empty($data['bank_reference']) || empty($data['verification_evidence']) || empty($data['bank_amount_cents'])) {
                        throw ValidationException::withMessages(['verified' => 'Se requiere verificacion bancaria real y su evidencia.']);
                    }
                    DB::table('bank_reference_locks')->where('id', 1)->lockForUpdate()->first();
                    $reference = self::reference($data['bank_reference']);
                    $referenceHash = hash('sha256', $reference);
                    $paymentId = $data['payment_id'] ?? null;
                    $payment = $paymentId ? Payment::whereKey($paymentId)->lockForUpdate()->first() : null;
                    if ($paymentId) {
                        abort_unless($payment && (int) $payment->cash_shift_id === (int) $shift->id && $payment->method === 'QR_STATIC' && $payment->status === 'qr_verified'
                            && $payment->reference && self::reference($payment->reference) === $reference, 409, 'El pago vinculado no esta verificado o no corresponde a la referencia y turno.');
                    }
                    $bankAmount = (int) $data['bank_amount_cents'];
                    abort_unless($bankAmount === (int) $data['amount_cents'] + ($payment?->amount_cents ?? 0), 409, 'La transferencia debe conciliar con el pago y el efectivo entregado.');
                    $duplicates = Payment::whereNotNull('reference')->whereRaw('LOWER(TRIM(reference)) = ?', [$reference]);
                    if ($payment) {
                        $duplicates->where('id', '<>', $payment->id);
                    }
                    abort_if($duplicates->lockForUpdate()->first() || DB::table('cash_movements')->where('bank_reference_hash', $referenceHash)->lockForUpdate()->first(), 409, 'Referencia bancaria ya utilizada.');
                }
                $summary = app(CashShiftService::class)->summary($shift);
                $available = $shift->opening_cents + $summary['cash_sales_cents'] + $summary['cash_inflows_cents'] - $summary['cash_outflows_cents'];
                abort_if($data['type'] !== 'IN' && (int) $data['amount_cents'] > $available, 409, 'Efectivo insuficiente.');
                $id = DB::table('cash_movements')->insertGetId(['uuid' => $data['uuid'], 'operation_id' => $data['operation_id'], 'request_hash' => $hash,
                    'cash_shift_id' => $shiftId, 'created_by' => $actor->id, 'authorized_by' => $actor->id, 'type' => $data['type'], 'amount_cents' => $data['amount_cents'],
                    'reason' => $data['reason'], 'bank_amount_cents' => $bankAmount, 'bank_reference' => $data['type'] === 'TRANSFER' ? $data['bank_reference'] : null,
                    'bank_reference_hash' => $referenceHash, 'verification_evidence' => $data['type'] === 'TRANSFER' ? $data['verification_evidence'] : null,
                    'payment_id' => $paymentId, 'created_at' => now('UTC')]);

                return [DB::table('cash_movements')->where('id', $id)->first(), false];
            }, 3);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                $prior = DB::table('cash_movements')->where('operation_id', $data['operation_id'])->first();
                if ($prior && (int) $prior->cash_shift_id === $shiftId && (int) $prior->created_by === (int) $actor->id && hash_equals($prior->request_hash, $hash)) {
                    return [$prior, true];
                }
                abort(409, 'Identificador o referencia ya utilizada.');
            }
            throw $e;
        }
    }
}
