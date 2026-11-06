<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\CatalogOption;
use App\Models\Category;
use App\Models\Extra;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }

    private function existing(array $data, string $hash, User $user): ?Order
    {
        $existing = Order::where('operation_id', $data['operation_id'])->first();
        if ($existing) {
            if ((int) $existing->seller_id !== (int) $user->id || ! hash_equals($existing->request_hash, $hash)) {
                abort(409, 'La operacion ya existe con datos diferentes o pertenece a otro usuario.');
            }

            return $existing->load(['items.extras', 'items.options', 'payment']);
        }
        if (Order::where('uuid', $data['uuid'])->exists()) {
            abort(409, 'El UUID ya pertenece a otro pedido.');
        }

        return null;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    /** @return array{0: Order, 1: bool} */
    public function create(array $data, User $user): array
    {
        if (isset($data['cash_shift_id']) && $data['payment_method'] !== 'CASH') {
            $this->invalid('cash_shift_id', 'Solo un pago en efectivo puede vincularse a un turno.');
        }
        $hash = hash('sha256', json_encode($this->canonicalize($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        if ($order = $this->existing($data, $hash, $user)) {
            return [$order, false];
        }
        try {
            $order = DB::transaction(function () use ($data, $hash, $user): Order {
                $cashShift = null;
                if (isset($data['cash_shift_id'])) {
                    User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $cashShift = CashShift::whereKey($data['cash_shift_id'])->where('user_id', $user->id)->lockForUpdate()->first();
                    if (! $cashShift || $cashShift->closed_at !== null || (int) $cashShift->active_user_id !== (int) $user->id) {
                        abort(409, 'El turno de caja no existe, no pertenece al vendedor o esta cerrado.');
                    }
                }
                // Reserve both unique keys before reading prices. A competing insert waits on this transaction.
                $order = Order::create(['uuid' => $data['uuid'], 'operation_id' => $data['operation_id'],
                    'device_id' => $data['device_id'], 'request_hash' => $hash, 'seller_id' => $user->id,
                    'customer_name' => $data['customer_name'], 'notes' => $data['notes'] ?? null,
                    'total_cents' => 0, 'payment_status' => 'unpaid', 'kitchen_status' => 'NEW']);
                $productIds = collect($data['items'])->pluck('product_id')->unique()->sort()->values()->all();
                $extraIds = collect($data['items'])->flatMap(fn ($line) => collect($line['extras'])->pluck('extra_id'))->unique()->sort()->values()->all();
                $optionIds = collect($data['items'])->flatMap(fn ($line) => $line['option_ids'])->unique()->sort()->values()->all();
                $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $categoryIds = $products->pluck('category_id')->unique()->sort()->values()->all();
                $categories = Category::whereIn('id', $categoryIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $extras = Extra::whereIn('id', $extraIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $options = CatalogOption::whereIn('id', $optionIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $total = 0;
                foreach ($data['items'] as $index => $line) {
                    $product = $products->get($line['product_id']);
                    if (! $product || ! $product->is_active || ! ($categories->get($product->category_id)?->is_active ?? false)) {
                        $this->invalid("items.$index.product_id", 'Producto inexistente o inactivo.');
                    }
                    if ((int) $line['expected_unit_price_cents'] !== $product->price_cents) {
                        abort(409, 'El precio del catalogo cambio; no se registro la venta.');
                    }
                    $selectedIds = array_column($line['extras'], 'extra_id');
                    if (count($selectedIds) !== count(array_unique($selectedIds)) || count($line['option_ids']) !== count(array_unique($line['option_ids']))) {
                        $this->invalid("items.$index", 'No se permiten extras u opciones repetidos en una linea.');
                    }
                    $allowed = $product->extras()->pluck('extras.id')->map(fn ($id) => (int) $id)->all();
                    $selected = [];
                    $selectedOptions = [];
                    $extraPrices = [];
                    foreach ($line['extras'] as $selection) {
                        $extra = $extras->get($selection['extra_id']);
                        if (! $extra || ! $extra->is_active || ! in_array((int) $extra->id, $allowed, true)) {
                            $this->invalid("items.$index.extras", 'Extra inexistente, inactivo o no autorizado para el producto.');
                        }
                        if ((int) $selection['expected_unit_price_cents'] !== $extra->price_cents) {
                            abort(409, 'El precio de un extra cambio; no se registro la venta.');
                        }
                        $extraPrices[] = $extra->price_cents;
                        $selected[] = $extra;
                    }
                    foreach ($line['option_ids'] as $optionId) {
                        $option = $options->get($optionId);
                        if (! $option || ! $option->is_active) {
                            $this->invalid("items.$index.option_ids", 'Opcion inexistente o inactiva.');
                        }
                        $selectedOptions[] = $option;
                    }
                    $lineTotal = app(OrderTotals::class)->line($product->price_cents, $extraPrices, (int) $line['quantity']);
                    $total += $lineTotal;
                    $item = $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name,
                        'quantity' => $line['quantity'], 'unit_price_cents' => $product->price_cents, 'line_total_cents' => $lineTotal, 'notes' => $line['notes'] ?? null]);
                    foreach ($selected as $extra) {
                        $item->extras()->create(['extra_id' => $extra->id, 'name' => $extra->name, 'unit_price_cents' => $extra->price_cents]);
                    }
                    foreach ($selectedOptions as $option) {
                        $item->options()->create(['catalog_option_id' => $option->id, 'name' => $option->name]);
                    }
                }
                if ($total !== (int) $data['expected_total_cents']) {
                    abort(409, 'El total esperado no coincide; no se registro la venta.');
                }
                $status = match ($data['payment_method']) {
                    'CASH' => 'cash_collected', 'QR_SIMULATED' => 'simulated', 'QR_STATIC' => 'qr_pending', default => 'unpaid'
                };
                $order->update(['total_cents' => $total, 'payment_status' => $status]);
                if ($data['payment_method'] !== 'UNPAID') {
                    $order->payment()->create(['method' => $data['payment_method'], 'status' => $status, 'amount_cents' => $total,
                        'cash_shift_id' => $cashShift?->id]);
                }

                return $order->load(['items.extras', 'items.options', 'payment']);
            }, 3);

            return [$order, true];
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true) && ($order = $this->existing($data, $hash, $user))) {
                return [$order, false];
            }
            throw $e;
        }
    }
}
