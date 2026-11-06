<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiSprintTest extends TestCase
{
    use RefreshDatabase;

    private function account(Role $role, bool $active = true): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => $active, 'password' => 'secure-password-123']);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_health_is_public_and_private_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.status', 'ok');
        $this->get('/api/v1/me')->assertUnauthorized()->assertJsonStructure(['error' => ['message']]);
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->postJson('/api/v1/register', [])->assertNotFound()->assertJsonStructure(['error' => ['message']]);
    }

    public function test_login_issues_a_token_and_logout_revokes_only_that_token(): void
    {
        $user = $this->account(Role::Seller);
        $other = $user->createToken('other')->plainTextToken;
        $login = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'secure-password-123', 'device_name' => 'Android'])
            ->assertOk()->assertJsonPath('data.user.role', 'vendedor')->assertJsonMissingPath('data.user.password');
        $token = $login->json('data.access_token');
        $this->assertNotEmpty($token);
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('data.id', $user->id);
        $this->postJson('/api/v1/logout', [], $headers)->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$other])->assertOk();
    }

    public function test_invalid_credentials_inactive_and_unassigned_users_cannot_login(): void
    {
        $active = $this->account(Role::Seller);
        $inactive = $this->account(Role::Kitchen, false);
        $unassigned = User::factory()->create(['role' => null, 'is_active' => true, 'password' => 'secure-password-123']);
        foreach ([[$active->email, 'wrong'], [$inactive->email, 'secure-password-123'], [$unassigned->email, 'secure-password-123'], ['missing@example.test', 'wrong']] as [$email, $password]) {
            $this->postJson('/api/v1/login', compact('email', 'password') + ['device_name' => 'test'])
                ->assertUnprocessable()->assertJsonStructure(['error' => ['message', 'details']]);
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_inactive_account_cannot_use_an_existing_token(): void
    {
        $user = $this->account(Role::Seller);
        $headers = $this->bearer($user);
        $user->update(['is_active' => false]);
        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
    }

    public function test_non_admin_roles_cannot_manage_users(): void
    {
        foreach ([Role::Seller, Role::Kitchen] as $role) {
            $headers = $this->bearer($this->account($role));
            $this->getJson('/api/v1/users', $headers)->assertForbidden();
            $this->postJson('/api/v1/users', [], $headers)->assertForbidden();
            $this->getJson('/api/v1/users/1', $headers)->assertForbidden();
            $this->patchJson('/api/v1/users/1', [], $headers)->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_admin_can_create_list_show_and_update_user_and_revoke_tokens(): void
    {
        $admin = $this->account(Role::Administrator);
        $headers = $this->bearer($admin);
        $response = $this->postJson('/api/v1/users', [
            'name' => 'Cocina', 'email' => 'kitchen@example.test', 'password' => 'secure-password-123', 'role' => 'cocina',
        ], $headers)->assertCreated()->assertJsonPath('data.role', 'cocina')->assertJsonMissingPath('data.password');
        $id = $response->json('data.id');
        $user = User::findOrFail($id);
        $this->assertTrue(Hash::check('secure-password-123', $user->password));
        $user->createToken('phone');
        $this->getJson('/api/v1/users', $headers)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/users/'.$id, $headers)->assertOk()->assertJsonPath('data.id', $id);
        $this->patchJson('/api/v1/users/'.$id, ['role' => 'vendedor'], $headers)->assertOk()->assertJsonPath('data.role', 'vendedor');
        $this->assertSame(0, $user->tokens()->count());
        $this->patchJson('/api/v1/users/'.$admin->id, ['is_active' => false], $headers)->assertUnprocessable();
        $this->patchJson('/api/v1/users/'.$admin->id, ['role' => 'cocina'], $headers)->assertUnprocessable();
    }

    public function test_invalid_roles_and_duplicate_email_are_rejected(): void
    {
        $admin = $this->account(Role::Administrator);
        $headers = $this->bearer($admin);
        $this->postJson('/api/v1/users', ['name' => 'X', 'email' => $admin->email, 'password' => 'short', 'role' => 'root'], $headers)
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['email', 'password', 'role']]]);
    }

    public function test_first_admin_command_requires_interaction(): void
    {
        $this->markTestSkipped('El comando de primer administrador esta prohibido en este sprint.');

        $this->artisan('rustik:create-first-admin', ['--no-interaction' => true])->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_first_admin_command_creates_only_the_first_admin(): void
    {
        $this->markTestSkipped('El comando de primer administrador esta prohibido en este sprint.');

        $this->artisan('rustik:create-first-admin')
            ->expectsQuestion('Nombre', 'Admin')
            ->expectsQuestion('Correo', 'admin@example.test')
            ->expectsQuestion('Contrase?a (m?nimo 12 caracteres)', 'secure-password-123')
            ->expectsQuestion('Confirme la contrase?a', 'secure-password-123')
            ->assertExitCode(0);
        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertSame(Role::Administrator, $admin->role);
        $this->assertTrue(Hash::check('secure-password-123', $admin->password));
        $this->artisan('rustik:create-first-admin')->assertExitCode(1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_permission_matrix_is_enforced_by_the_model(): void
    {
        $admin = $this->account(Role::Administrator);
        $seller = $this->account(Role::Seller);
        $kitchen = $this->account(Role::Kitchen);
        $this->assertTrue($admin->hasPermission('users.manage'));
        $this->assertTrue($admin->hasPermission('reports.view'));
        $this->assertTrue($seller->hasPermission('payments.create'));
        $this->assertFalse($seller->hasPermission('users.manage'));
        $this->assertTrue($kitchen->hasPermission('orders.prepare'));
        $this->assertFalse($kitchen->hasPermission('payments.create'));
        $this->assertFalse($kitchen->hasPermission('reports.view'));
        $this->assertFalse($admin->hasPermission('unknown.permission'));
    }
}
