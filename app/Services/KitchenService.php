<?php

namespace App\Services;

use App\Enums\KitchenStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KitchenService
{
    public function transition(string $uuid, string $expected, string $next, string $operation, User $user): array
    {
        $target = KitchenStatus::from($next);
        $permission = match ($target) {
            KitchenStatus::Preparing => 'orders.prepare',
            KitchenStatus::Ready => 'orders.ready',
            KitchenStatus::Delivered => 'orders.deliver',
            KitchenStatus::New => null,
        };
        if ($permission !== null) {
            abort_unless($user->hasPermission($permission), 403, 'No tiene permiso para esta accion.');
        }

        try {
            return DB::transaction(function () use ($uuid, $expected, $target, $operation, $user): array {
                $query = Order::where('uuid', $uuid);
                if ($user->role === Role::Seller) {
                    $query->where('seller_id', $user->id);
                }
                $order = $query->lockForUpdate()->firstOrFail();
                $event = DB::table('order_status_events')->where('operation_id', $operation)->first();
                if ($event) {
                    abort_unless($event->order_id === $order->id && (int) $event->user_id === (int) $user->id
                        && $event->previous_status === $expected && $event->new_status === $target->value, 409, 'La operacion ya fue utilizada con otros datos.');

                    return [$order, true];
                }
                if ($order->kitchen_status !== $expected) {
                    abort(409, 'El estado anterior esta desactualizado.');
                }
                if (KitchenStatus::from($expected)->next() !== $target) {
                    throw ValidationException::withMessages(['status' => ['Transicion de cocina invalida.']]);
                }
                $now = now('UTC');
                $field = match ($target) {
                    KitchenStatus::Preparing => 'preparing_at',
                    KitchenStatus::Ready => 'ready_at',
                    KitchenStatus::Delivered => 'delivered_at',
                    default => throw new \LogicException('Invalid target'),
                };
                $order->update(['kitchen_status' => $target->value, $field => $now]);
                DB::table('order_status_events')->insert(['order_id' => $order->id, 'order_uuid' => $order->uuid,
                    'previous_status' => $expected, 'new_status' => $target->value, 'user_id' => $user->id,
                    'operation_id' => $operation, 'created_at' => $now]);

                return [$order, false];
            }, 3);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                abort(409, 'La operacion ya fue utilizada con otros datos.');
            }
            throw $e;
        }
    }
}
