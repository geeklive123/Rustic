<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    public function test_server_errors_never_reflect_internal_details(): void
    {
        Route::get('/api/v1/security-test-error', fn () => response()->json(['message' => 'internal-marker', 'errors' => ['secret' => ['secret-marker']]], 500))->middleware('api');
        $this->getJson('/api/v1/security-test-error')->assertStatus(500)->assertExactJson(['error' => ['message' => 'Error interno del servidor.']]);
        Route::get('/api/v1/security-test-exception', function () {
            throw new HttpResponseException(response()->json(['message' => 'internal-marker', 'errors' => ['secret' => ['secret-marker']]], 500));
        })->middleware('api');
        $this->getJson('/api/v1/security-test-exception')->assertStatus(500)->assertExactJson(['error' => ['message' => 'Error interno del servidor.']]);
    }

    public function test_cors_denies_unconfigured_origins_and_allows_only_configured_origin(): void
    {
        config()->set('cors.allowed_origins', []);
        $this->call('OPTIONS', '/api/v1/health', server: ['HTTP_ORIGIN' => 'https://untrusted.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
        config()->set('cors.allowed_origins', ['https://trusted.example']);
        $this->call('OPTIONS', '/api/v1/health', server: ['HTTP_ORIGIN' => 'https://trusted.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://trusted.example')->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_forwarded_headers_only_apply_from_configured_proxy(): void
    {
        Route::get('/api/v1/security-test-proxy', fn (Request $request) => response()->json(['ip' => $request->ip(), 'secure' => $request->isSecure()]))->middleware('api');
        $server = ['REMOTE_ADDR' => '10.0.0.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.99', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        config()->set('trustedproxy.proxies', []);
        $this->getJson('/api/v1/security-test-proxy', $server)->assertOk()->assertJsonPath('ip', '10.0.0.10')->assertJsonPath('secure', false);
        config()->set('trustedproxy.proxies', ['10.0.0.10']);
        $this->getJson('/api/v1/security-test-proxy', $server)->assertOk()->assertJsonPath('ip', '203.0.113.99')->assertJsonPath('secure', true);
    }

    public function test_login_is_rate_limited_without_exposing_credentials(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => 'nobody@example.test', 'password' => 'sensitive-marker', 'device_name' => 'test'])->assertUnprocessable()->assertDontSee('sensitive-marker');
        }
        $this->postJson('/api/v1/login', ['email' => 'nobody@example.test', 'password' => 'sensitive-marker', 'device_name' => 'test'])
            ->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)->assertDontSee('sensitive-marker');
    }

    public function test_other_sellers_cannot_query_order_status_or_deliver(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $owner = $this->accountFor(Role::Seller);
        $payload = $this->payload($product, $extra, $option);
        $this->postJson('/api/v1/orders', $payload, $this->tokenFor($owner))->assertCreated();
        $this->app['auth']->forgetGuards();
        $other = $this->tokenFor($this->accountFor(Role::Seller));
        foreach (['', '/status', '/sync-status'] as $suffix) {
            $this->getJson('/api/v1/orders/'.$payload['uuid'].$suffix, $other)->assertNotFound();
        }
        $this->patchJson('/api/v1/kitchen/orders/'.$payload['uuid'].'/status', ['expected_status' => 'READY', 'status' => 'DELIVERED', 'operation_id' => (string) Str::uuid()], $other)->assertNotFound();
    }

    public function test_financial_route_retains_its_sixty_request_limit(): void
    {
        $headers = $this->tokenFor($this->accountFor(Role::Administrator));
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/reports/summary', $headers)->assertOk();
        }
        $this->getJson('/api/v1/reports/summary', $headers)->assertStatus(429);
    }

    public function test_authenticated_requests_are_limited_without_affecting_login_bucket(): void
    {
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/me', $headers)->assertOk();
        }
        $this->getJson('/api/v1/me', $headers)->assertStatus(429);
    }

    public function test_cash_order_can_use_shared_shift(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $owner = $this->tokenFor($this->accountFor(Role::Administrator));
        $id = $this->postJson('/api/v1/cash-shifts/open', ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0], $owner)->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $other = $this->tokenFor($this->accountFor(Role::Seller));
        $body = $this->payload($product, $extra, $option);
        $body['cash_shift_id'] = $id;
        $this->postJson('/api/v1/orders', $body, $other)->assertCreated();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('payments', ['cash_shift_id' => $id, 'status' => 'cash_collected']);
    }
}
