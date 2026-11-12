<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\CatalogOption;
use App\Models\Category;
use App\Models\Extra;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
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

            return $existing->load(['items.extras', 'items.options', 'items.components.extras', 'items.components.options', 'payment']);
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
        $hash = hash('sha256', json_encode($this->canonicalize($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        if ($order = $this->existing($data, $hash, $user)) {
            return [$order, false];
        }
        if (config('cash.require_shift') && ! isset($data['cash_shift_id'])) {
            abort(409, 'Debe seleccionar una caja abierta para registrar el pedido.');
        }
        try {
            $order = DB::transaction(function () use ($data, $hash, $user): Order {
                $cashShift = null;
                if (isset($data['cash_shift_id'])) {
                    $cashShift = CashShift::whereKey($data['cash_shift_id'])->lockForUpdate()->first();
                    if (! $cashShift || $cashShift->closed_at !== null || (int) $cashShift->active_global !== 1) {
                        abort(409, 'El turno de caja no existe o esta cerrado.');
                    }
                }
                // Reserve both unique keys before reading prices. A competing insert waits on this transaction.
                $order = Order::create(['uuid' => $data['uuid'], 'operation_id' => $data['operation_id'],
                    'device_id' => $data['device_id'], 'request_hash' => $hash, 'seller_id' => $user->id, 'cash_shift_id' => $cashShift?->id,
                    'customer_name' => $data['customer_name'], 'notes' => $data['notes'] ?? null,
                    'total_cents' => 0, 'payment_status' => 'unpaid', 'kitchen_status' => 'NEW']);
                $productIds = collect($data['items'])->pluck('product_id')->unique()->sort()->values()->all();
                $extraIds = collect($data['items'])->flatMap(fn ($line) => collect($line['extras'])->concat(collect($line['components'] ?? [])->flatMap(fn ($part) => $part['extras']))->pluck('extra_id'))->unique()->sort()->values()->all();
                $optionIds = collect($data['items'])->flatMap(fn ($line) => collect($line['option_ids'])->concat(collect($line['components'] ?? [])->flatMap(fn ($part) => $part['option_ids'])))->unique()->sort()->values()->all();
                $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $categoryIds = $products->pluck('category_id')->unique()->sort()->values()->all();
                $categories = Category::whereIn('id', $categoryIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $extras = Extra::whereIn('id', $extraIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $options = CatalogOption::whereIn('id', $optionIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $authorizationIds = collect($data['items'])->pluck('price_authorization_id')->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->all();
                if (count($authorizationIds) !== count(array_unique($authorizationIds))) {
                    $this->invalid('items', 'Una autorizaci?n no puede utilizarse en m?s de una l?nea.');
                }
                sort($authorizationIds);
                $authorizations = $authorizationIds === [] ? collect() : DB::table('special_price_authorizations')->whereIn('id', $authorizationIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $approverIds = $authorizations->pluck('authorized_by')->unique()->sort()->values()->all();
                $approvers = $approverIds === [] ? collect() : User::whereIn('id', $approverIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $total = 0;
                foreach ($data['items'] as $index => $line) {
                    $product = $products->get($line['product_id']);
                    if (! $product || ! $product->is_active || ! ($categories->get($product->category_id)?->is_active ?? false)) {
                        $this->invalid("items.$index.product_id", 'Producto inexistente o inactivo.');
                    }
                    $unitPrice = $product->price_cents;
                    $authorization = null;
                    if (isset($line['price_authorization_id'])) {
                        $authorization = $authorizations->get((int) $line['price_authorization_id']);
                        abort_unless($authorization && $authorization->used_at === null
                            && (int) $authorization->seller_id === (int) $user->id
                            && (int) $authorization->product_id === (int) $product->id
                            && (int) $authorization->quantity === (int) $line['quantity']
                            && $authorization->order_uuid === strtolower($data['uuid'])
                            && ($approvers->get($authorization->authorized_by)?->hasPermission('menu.manage') ?? false)
                            && CarbonImmutable::parse($authorization->expires_at, 'UTC')->isAfter(now('UTC'))
                            && (int) $authorization->normal_cents === $product->price_cents, 409, 'Autorizaci?n de precio inv?lida, vencida o utilizada.');
                        $unitPrice = (int) $authorization->authorized_cents;
                    }
                    if ((int) $line['expected_unit_price_cents'] !== $unitPrice) {
                        abort(409, 'El precio del catalogo o la autorizaci?n cambi?; no se registro la venta.');
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
                    $componentSelections = [];
                    if (isset($line['components'])) {
                        if ((int) $line['quantity'] !== 1 || $line['extras'] !== [] || $line['option_ids'] !== []) {
                            $this->invalid("items.$index.components", 'Los componentes requieren cantidad 1 y extras y opciones de l?nea vac?os.');
                        }
                        if (! array_key_exists('combo_revision', $line)) {
                            $this->invalid("items.$index.combo_revision", 'Se requiere la revisi?n del cat?logo para personalizar.');
                        }
                        abort_unless((int) $line['combo_revision'] === (int) $product->combo_revision, 409, 'La composici?n cambi?; actualice el cat?logo.');
                        foreach ($line['components'] as $componentIndex => $component) {
                            if (! isset($component['component_id']) || array_key_exists('name', $component)) {
                                $this->invalid("items.$index.components.$componentIndex", 'Use el identificador del componente del cat?logo.');
                            }
                        }
                        $definitions = $product->comboComponents()->where('is_active', true)->with(['extras', 'options'])->get()->keyBy('id');
                        if ($definitions->isEmpty()) {
                            $this->invalid("items.$index.components", 'El producto no tiene composici?n configurada.');
                        }
                        $counts = collect($line['components'])->countBy(fn ($component) => (int) $component['component_id']);
                        foreach ($definitions as $definition) {
                            if (($counts[$definition->id] ?? 0) !== $definition->quantity) {
                                $this->invalid("items.$index.components", 'Los componentes no corresponden a las cantidades del cat?logo.');
                            }
                        }
                        if ($counts->keys()->diff($definitions->keys())->isNotEmpty()) {
                            $this->invalid("items.$index.components", 'El componente no pertenece al producto.');
                        }
                        $componentNumbers = [];
                        foreach ($line['components'] as $componentIndex => $component) {
                            $definition = $definitions->get((int) $component['component_id']);
                            if (! $definition->customizable && ($component['extras'] !== [] || $component['option_ids'] !== [] || ! empty($component['notes']))) {
                                $this->invalid("items.$index.components.$componentIndex", 'Este componente no permite personalizaci?n.');
                            }
                            $permittedExtras = $definition->extras->pluck('id')->map(fn ($id) => (int) $id)->all();
                            $permittedOptions = $definition->options->pluck('id')->map(fn ($id) => (int) $id)->all();
                            $componentExtras = [];
                            $componentOptions = [];
                            $ids = array_column($component['extras'], 'extra_id');
                            if (count($ids) !== count(array_unique($ids)) || count($component['option_ids']) !== count(array_unique($component['option_ids']))) {
                                $this->invalid("items.$index.components.$componentIndex", 'No se permiten extras u opciones repetidos en un componente.');
                            }
                            foreach ($component['extras'] as $selection) {
                                $extra = $extras->get($selection['extra_id']);
                                if (! $extra || ! $extra->is_active || ! in_array((int) $extra->id, $allowed, true) || ! in_array((int) $extra->id, $permittedExtras, true)) {
                                    $this->invalid("items.$index.components.$componentIndex.extras", 'Extra inexistente, inactivo o no autorizado para el producto.');
                                }
                                if ((int) $selection['expected_unit_price_cents'] !== $extra->price_cents) {
                                    abort(409, 'El precio de un extra cambio; no se registro la venta.');
                                }
                                $extraPrices[] = $extra->price_cents;
                                $componentExtras[] = $extra;
                            }
                            foreach ($component['option_ids'] as $optionId) {
                                $option = $options->get($optionId);
                                if (! $option || ! $option->is_active || ! in_array((int) $option->id, $permittedOptions, true)) {
                                    $this->invalid("items.$index.components.$componentIndex.option_ids", 'Opci?n inexistente o inactiva.');
                                }
                                $componentOptions[] = $option;
                            }
                            $componentNumbers[$definition->id] = ($componentNumbers[$definition->id] ?? 0) + 1;
                            $name = $definition->name.($definition->quantity > 1 ? ' '.$componentNumbers[$definition->id] : '');
                            $componentSelections[] = ['data' => $component, 'definition' => $definition, 'name' => $name, 'extras' => $componentExtras, 'options' => $componentOptions];
                        }
                    }
                    $lineTotal = app(OrderTotals::class)->line($unitPrice, $extraPrices, (int) $line['quantity']);
                    $total += $lineTotal;
                    $item = $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name,
                        'quantity' => $line['quantity'], 'unit_price_cents' => $unitPrice, 'line_total_cents' => $lineTotal, 'notes' => $line['notes'] ?? null]);
                    if ($authorization) {
                        DB::table('special_price_authorizations')->where('id', $authorization->id)->update([
                            'used_at' => now('UTC'), 'order_id' => $order->id, 'order_item_id' => $item->id,
                        ]);
                    }
                    foreach ($componentSelections as $position => $selection) {
                        $component = $item->components()->create(['position' => $position + 1, 'name' => $selection['name'], 'catalog_component_id' => $selection['definition']->id, 'notes' => $selection['data']['notes'] ?? null]);
                        foreach ($selection['extras'] as $extra) {
                            $component->extras()->create(['extra_id' => $extra->id, 'name' => $extra->name, 'unit_price_cents' => $extra->price_cents]);
                        }
                        foreach ($selection['options'] as $option) {
                            $component->options()->create(['catalog_option_id' => $option->id, 'name' => $option->name]);
                        }
                    }
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

                return $order->load(['items.extras', 'items.options', 'items.components.extras', 'items.components.options', 'payment']);
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
