<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();
        // Always check a hash to reduce account enumeration by timing.
        $valid = Hash::check($data['password'], $user?->password ?? '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxeZ8UBW7PF1bcKp5r3bH5S5S3K');
        if (! $valid || ! $user || ! $user->is_active || $user->role === null) {
            throw ValidationException::withMessages(['email' => ['Credenciales inv?lidas o cuenta inactiva.']]);
        }

        return response()->json(['data' => [
            'token_type' => 'Bearer',
            'access_token' => $user->createToken($data['device_name'])->plainTextToken,
            'user' => UserController::present($user),
        ]]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => UserController::present($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['message' => 'Sesi?n cerrada.']]);
    }
}
