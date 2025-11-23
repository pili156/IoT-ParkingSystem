<?php

namespace App\Http\Controllers\Api;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Controller;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $r->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $admin = Admin::where('email', $r->email)->first();

        if (!$admin || !Hash::check($r->password, $admin->password)) {
            return response()->json(['error'=>'Invalid credentials'], 401);
        }

        return [
            'token' => $admin->createToken('api')->plainTextToken,
            'admin' => $admin
        ];
    }
}
