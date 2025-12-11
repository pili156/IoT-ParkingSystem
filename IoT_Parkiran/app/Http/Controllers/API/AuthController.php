<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Login device/user untuk mendapatkan token API
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_name' => 'nullable|string|max:255'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Invalid credentials', [], 401);
        }

        // Delete existing tokens for this device if provided
        if ($request->device_name) {
            $user->tokens()->where('name', $request->device_name)->delete();
        }

        $deviceName = $request->device_name ?? 'web_session_' . time();
        $token = $user->createToken($deviceName, ['*'])->plainTextToken;

        return $this->successResponse([
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer'
        ], 'Login successful');
    }

    /**
     * Register device baru (untuk ESP32 atau Python ANPR)
     */
    public function registerDevice(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8',
            'device_type' => 'required|in:esp32,anpr_python,web_admin',
            'device_name' => 'required|string|max:255'
        ]);

        $user = User::create([
            'name' => $request->device_name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $abilities = $this->mapDeviceAbilities($request->device_type);
        $token = $user->createToken($request->device_name, $abilities)->plainTextToken;

        return $this->successResponse([
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
            'abilities' => $abilities
        ], 'Device registered successfully');
    }

    /**
     * Logout (menghapus token saat ini)
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse(null, 'Logged out successfully');
    }

    /**
     * Mendapatkan info user saat ini
     */
    public function me(Request $request)
    {
        return $this->successResponse($request->user(), 'User data retrieved');
    }

    /**
     * Mapping kemampuan token berdasarkan tipe device
     */
    private function mapDeviceAbilities($deviceType)
    {
        switch ($deviceType) {
            case 'esp32':
                return ['iot:*']; // ESP32 bisa mengirim event dan membaca command
            case 'anpr_python':
                return ['anpr:*']; // ANPR Python bisa menyimpan hasil ANPR
            case 'web_admin':
                return ['parking:*', 'anpr:read', 'iot:read']; // Admin bisa lihat semua
            default:
                return ['*']; // Default semua akses
        }
    }
}