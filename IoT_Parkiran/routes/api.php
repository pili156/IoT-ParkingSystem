<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ParkingSpaceController;
use App\Http\Controllers\API\ANPRController;
use App\Http\Controllers\API\IoTController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Jalur-jalur API ini diproteksi dengan token Sanctum, cocok untuk
| komunikasi antar mesin (Python Script & ESP32) serta dashboard web.
|
*/

// Public routes (for device registration and login only)
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register-device', [AuthController::class, 'registerDevice']);
});

// Protected routes (memerlukan autentikasi token Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    
    // Rute utama sistem parkir
    Route::prefix('parking')->group(function () {
        // Info slot parkir
        Route::get('/slots', [ParkingSpaceController::class, 'index']);
        Route::get('/slots/{slot}', [ParkingSpaceController::class, 'show']);
        
        // Status parkir
        Route::post('/slots/status', [ParkingSpaceController::class, 'updateStatus']);
        
        // Riwayat kendaraan
        Route::get('/entries', [ParkingSpaceController::class, 'getEntries']);
        Route::get('/exits', [ParkingSpaceController::class, 'getExits']);
        Route::get('/current-vehicles', [ParkingSpaceController::class, 'getCurrentVehicles']);
    });
    
    // Rute khusus ANPR (Python script)
    Route::prefix('anpr')->group(function () {
        Route::post('/result', [ANPRController::class, 'storeResult']);
        Route::get('/results', [ANPRController::class, 'getResults']);
    });
    
    // Rute khusus IoT (ESP32)
    Route::prefix('iot')->group(function () {
        Route::post('/event', [IoTController::class, 'handleEvent']);
        Route::get('/command', [IoTController::class, 'getCommand']);
        Route::post('/command/consume', [IoTController::class, 'consumeCommand']);
    });
    
    // Rute autentikasi untuk manajemen token
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});