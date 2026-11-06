<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class KitchenTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    private function setupOrder(): array
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->accountFor(Role::Seller);
        $sellerHeaders = $this->tokenFor($seller);
        $data = $this->payload($product, $extra, $option);
        $this->postJson('/api/v1/orders', $data, $sellerHeaders)->assertCreated();
        $this->app['auth']->forgetGuards();
        $kitchen = $this->accountFor(Role::Kitchen);

        return [$data['uuid'], $seller, $sellerHeaders, $kitchen, $this->tokenFor($kitchen)];
    }

    private function change(string $uuid, array $headers, string $from, string $to, ?string $operation = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->patchJson('/api/v1/kitchen/orders/'.$uuid.'/status', ['expected_status' => $from,
            'status' => $to, 'operation_id' => $operation ?? (string) Str::uuid()], $headers);
    }

    public function test_full_workflow_dates_audit_idempotency_and_payment_independence(): void
    {
        [$uuid, $seller, $sellerHeaders, $kitchen, $kitchenHeaders] = $this->setupOrder();
        $operation = (string) Str::uuid();
        $this->change($uuid, $kitchenHeaders, 'NEW', 'PREPARING', $operation)->assertOk()->assertJsonPath('replayed', false)->assertJsonPath('data.kitchen_status', 'PREPARING');
        $this->change($uuid, $kitchenHeaders, 'NEW', 'PREPARING', $operation)->assertOk()->assertJsonPath('replayed', true);
        $this->change($uuid, $kitchenHeaders, 'PREPARING', 'READY', $operation)->assertStatus(409);
        $this->change($uuid, $kitchenHeaders, 'NEW', 'PREPARING')->assertStatus(409);
        $this->change($uuid, $kitchenHeaders, 'PREPARING', 'READY')->assertOk();
        $this->change($uuid, $sellerHeaders, 'READY', 'DELIVERED')->assertOk()->assertJsonPath('data.kitchen_status', 'DELIVERED');
        $this->change($uuid, $sellerHeaders, 'DELIVERED', 'DELIVERED')->assertUnprocessable();
        $order = Order::where('uuid', $uuid)->firstOrFail();
        $this->assertNotNull($order->preparing_at);
        $this->assertNotNull($order->ready_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame('cash_collected', $order->payment_status);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_status_events', 3);
        $events = DB::table('order_status_events')->orderBy('id')->get();
        $this->assertSame(['NEW', 'PREPARING', 'READY'], $events->pluck('previous_status')->all());
        $this->assertSame(['PREPARING', 'READY', 'DELIVERED'], $events->pluck('new_status')->all());
        $this->assertSame([(int) $kitchen->id, (int) $kitchen->id, (int) $seller->id], $events->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$uuid, $uuid, $uuid], $events->pluck('order_uuid')->all());
        $this->assertNotNull($events[0]->created_at);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/orders/'.$uuid.'/status', $sellerHeaders)->assertOk()->assertJsonPath('data.kitchen_status', 'DELIVERED');
        $this->getJson('/api/v1/orders/'.$uuid, $sellerHeaders)->assertOk();
    }

    public function test_queue_filters_pagination_and_no_financial_information(): void
    {
        [$uuid, , , , $headers] = $this->setupOrder();
        $this->app['auth']->forgetGuards();
        $r = $this->getJson('/api/v1/kitchen/orders?status=NEW&per_page=1&updated_since=2000-01-01T00%3A00%3A00%2B00%3A00', $headers)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.uuid', $uuid)->assertJsonPath('data.0.items.0.extras.0.name', 'Queso extra')
            ->assertJsonPath('data.0.items.0.options.0.name', 'Con llajua');
        foreach (['total_cents', 'payment', 'payment_status', 'items.0.unit_price_cents', 'items.0.line_total_cents', 'items.0.extras.0.unit_price_cents'] as $field) {
            $r->assertJsonMissingPath('data.0.'.$field);
        }
        $this->assertStringNotContainsString('price', json_encode($r->json()));
        $this->change($uuid, $headers, 'NEW', 'PREPARING')->assertOk();
        $this->getJson('/api/v1/kitchen/orders?status=PREPARING', $headers)->assertOk()->assertJsonPath('meta.total', 1);
        $this->change($uuid, $headers, 'PREPARING', 'READY')->assertOk();
        $this->getJson('/api/v1/kitchen/orders?status=READY', $headers)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/kitchen/orders?updated_since=2100-01-01T00%3A00%3A00%2B00%3A00', $headers)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/kitchen/orders/'.$uuid, $headers)->assertOk()->assertJsonMissingPath('data.total_cents');
        $this->getJson('/api/v1/kitchen/orders?status=BAD', $headers)->assertUnprocessable()->assertJsonMissingPath('error.details.total_cents');
        $this->getJson('/api/v1/reports', $headers)->assertNotFound();
    }

    public function test_auth_authorization_stale_and_invalid_transitions(): void
    {
        [$uuid, , $seller, , $kitchen] = $this->setupOrder();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/kitchen/orders')->assertUnauthorized();
        $this->getJson('/api/v1/kitchen/orders', $seller)->assertForbidden();
        $this->change($uuid, $seller, 'NEW', 'PREPARING')->assertForbidden();
        $this->change($uuid, $seller, 'NEW', 'DELIVERED')->assertUnprocessable();
        $this->change($uuid, $kitchen, 'NEW', 'READY')->assertUnprocessable();
        $this->change($uuid, $kitchen, 'NEW', 'DELIVERED')->assertForbidden();
        $this->change($uuid, $kitchen, 'READY', 'PREPARING')->assertStatus(409);
        $this->change((string) Str::uuid(), $kitchen, 'NEW', 'PREPARING')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $admin = $this->tokenFor($this->accountFor(Role::Administrator));
        $this->change($uuid, $admin, 'NEW', 'PREPARING')->assertOk();
        $this->change($uuid, $admin, 'PREPARING', 'READY')->assertOk();
        $this->app['auth']->forgetGuards();
        $otherSeller = $this->tokenFor($this->accountFor(Role::Seller));
        $this->change($uuid, $otherSeller, 'READY', 'DELIVERED')->assertNotFound();
        $this->change($uuid, $admin, 'READY', 'DELIVERED')->assertOk();
        $this->assertDatabaseCount('order_status_events', 3);
    }

    public function test_incremental_pages_reuse_snapshot_and_advance_by_id(): void
    {
        [$uuid, , , , $headers] = $this->setupOrder();
        $order = Order::where('uuid', $uuid)->firstOrFail();
        $copy = $order->replicate();
        $copy->uuid = (string) Str::uuid();
        $copy->operation_id = (string) Str::uuid();
        $copy->save();
        $this->app['auth']->forgetGuards();
        $first = $this->getJson('/api/v1/kitchen/orders?per_page=1', $headers)->assertOk()->assertJsonPath('meta.total', 2);
        $snapshot = $first->json('meta.snapshot_until');
        $next = $first->json('meta.next_after_id');
        $this->assertNotNull($next);
        $query = http_build_query(['per_page' => 1, 'after_id' => $next, 'snapshot_until' => $snapshot,
            'updated_since' => $first->json('data.0.updated_at')]);
        $this->getJson('/api/v1/kitchen/orders?'.$query, $headers)->assertOk()->assertJsonPath('data.0.uuid', $copy->uuid)
            ->assertJsonPath('meta.next_after_id', null);
    }
}
