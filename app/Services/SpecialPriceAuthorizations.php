<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SpecialPriceAuthorizations
{
    public function issue(User $actor, array $data): object
    {
        abort_unless($actor->hasPermission('menu.manage'), 403);
        $data['order_uuid'] = strtolower($data['order_uuid']);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        try {
            return DB::transaction(function () use ($actor, $data, $hash) {
                $prior = DB::table('special_price_authorizations')->where('operation_id', $data['operation_id'])->first();
                if ($prior) {
                    abort_unless((int) $prior->authorized_by === (int) $actor->id && hash_equals($prior->request_hash, $hash), 409, 'Operaci?n reutilizada con otros datos.');

                    return $prior;
                }
                $product = Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
                $seller = User::whereKey($data['seller_id'])->firstOrFail();
                if (! $product->is_active || ! $product->category?->is_active || ! $seller->is_active || ! in_array($seller->role, [Role::Seller, Role::Kitchen], true)) {
                    throw ValidationException::withMessages(['seller_id' => 'Seleccione un producto disponible y un usuario de ventas activo.']);
                }
                if ((int) $data['authorized_cents'] === $product->price_cents) {
                    throw ValidationException::withMessages(['price' => 'El precio especial debe ser distinto del precio normal.']);
                }
                if (Order::where('uuid', $data['order_uuid'])->exists()) {
                    abort(409, 'El pedido ya existe; no se puede autorizar un precio retroactivo.');
                }
                $id = DB::table('special_price_authorizations')->insertGetId(['operation_id' => $data['operation_id'], 'request_hash' => $hash,
                    'authorized_by' => $actor->id, 'seller_id' => $seller->id, 'product_id' => $product->id, 'order_uuid' => $data['order_uuid'],
                    'quantity' => $data['quantity'], 'normal_cents' => $product->price_cents, 'authorized_cents' => $data['authorized_cents'],
                    'reason' => $data['reason'], 'created_at' => now('UTC'), 'expires_at' => now('UTC')->addHour()]);

                return DB::table('special_price_authorizations')->where('id', $id)->first();
            }, 3);
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }
            $prior = DB::table('special_price_authorizations')->where('operation_id', $data['operation_id'])->first();
            abort_unless($prior && (int) $prior->authorized_by === (int) $actor->id && hash_equals($prior->request_hash, $hash), 409, 'Operaci?n reutilizada con otros datos.');

            return $prior;
        }
    }
}
