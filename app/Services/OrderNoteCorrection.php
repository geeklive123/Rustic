<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderNoteCorrection
{
    public function update(int $id, User $actor, array $data): void
    {
        $hash = hash('sha256', json_encode([$id, $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        try {
            DB::transaction(function () use ($id, $actor, $data, $hash) {
                $order = Order::whereKey($id)->lockForUpdate()->firstOrFail();
                $prior = DB::table('order_admin_corrections')->where('operation_id', $data['operation_id'])->first();
                if ($prior) {
                    abort_unless((int) $prior->user_id === (int) $actor->id && (int) $prior->order_id === $id && hash_equals($prior->request_hash, $hash), 409, 'Operaci?n reutilizada con otros datos.');

                    return;
                }
                abort_unless(in_array($order->kitchen_status, ['NEW', 'PREPARING'], true) && $order->kitchen_status === $data['expected_status'], 409, 'El estado de Cocina cambi? o no permite correcciones.');
                abort_unless(hash_equals($data['expected_note_hash'], hash('sha256', $order->notes ?? '')), 409, 'Las observaciones cambiaron; actualice la p?gina.');
                if ($order->kitchen_status === 'PREPARING' && empty($data['confirmed'])) {
                    throw ValidationException::withMessages(['confirmed' => 'Confirme el cambio durante la preparaci?n.']);
                }
                $note = $data['notes'] ?? null;
                if (($order->notes ?? null) === $note) {
                    throw ValidationException::withMessages(['notes' => 'No hay cambios en las observaciones.']);
                }
                DB::table('order_admin_corrections')->insert(['operation_id' => $data['operation_id'], 'order_id' => $order->id, 'user_id' => $actor->id,
                    'request_hash' => $hash, 'action' => 'preparation_note', 'before_value' => $order->notes, 'after_value' => $note, 'reason' => $data['reason'], 'created_at' => now('UTC')]);
                $order->update(['notes' => $note]);
            }, 3);
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }
            $prior = DB::table('order_admin_corrections')->where('operation_id', $data['operation_id'])->first();
            abort_unless($prior && (int) $prior->user_id === (int) $actor->id && (int) $prior->order_id === $id && hash_equals($prior->request_hash, $hash), 409, 'Operaci?n reutilizada con otros datos.');
        }
    }
}
