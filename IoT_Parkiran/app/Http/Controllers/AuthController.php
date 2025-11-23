<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function doLogin(Request $r)
    {
        $credentials = $r->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (Auth::attempt($credentials)) {
            $r->session()->regenerate();
            return redirect()->route('dashboard');
        }

        return back()->with(['error' => 'Email atau password salah!']);
    }

    public function logout(Request $r)
    {
        Auth::logout();

        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }
}
