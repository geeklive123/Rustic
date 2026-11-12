<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function form()
    {
        return view('admin.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:1024']);
        $user = User::where('email', $data['email'])->first();
        $valid = Hash::check($data['password'], $user?->password ?? '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxeZ8UBW7PF1bcKp5r3bH5S5S3K');
        if (! $valid || ! $user?->hasPermission('cash.manage')) {
            throw ValidationException::withMessages(['email' => 'Credenciales inv?lidas o acceso no autorizado.']);
        }
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('cash.current'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
