<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public static function present(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'role' => $user->role?->value, 'is_active' => $user->is_active,
            'permissions' => $user->is_active ? ($user->role?->permissions() ?? []) : [],
            'created_at' => $user->created_at?->toISOString(),
        ];
    }

    public function index(): JsonResponse
    {
        $users = User::orderBy('id')->paginate(25);

        return response()->json(['data' => array_map(self::present(...), $users->items()), 'meta' => [
            'current_page' => $users->currentPage(), 'last_page' => $users->lastPage(),
            'per_page' => $users->perPage(), 'total' => $users->total(),
        ]]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => self::present($user)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(12), 'max:1024'],
            'role' => ['required', Rule::enum(Role::class)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['is_active'] ??= true;
        $user = User::create($data);

        return response()->json(['data' => self::present($user)], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'required', 'string', Password::min(12), 'max:1024'],
            'role' => ['sometimes', 'required', Rule::enum(Role::class)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);
        if ($request->user()->is($user) && (($data['role'] ?? Role::Administrator->value) !== Role::Administrator->value || array_key_exists('is_active', $data) && ! $data['is_active'])) {
            throw ValidationException::withMessages(['user' => ['No puede desactivar ni quitarse el rol de administrador.']]);
        }

        DB::transaction(function () use ($user, $data): void {
            $user->fill($data);
            $revoke = $user->isDirty(['password', 'role', 'is_active']);
            $user->save();
            if ($revoke) {
                $user->tokens()->delete();
            }
        });

        return response()->json(['data' => self::present($user)]);
    }
}
