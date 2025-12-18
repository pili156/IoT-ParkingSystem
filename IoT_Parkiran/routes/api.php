<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Import Controller yang SUDAH kita perbaiki tadi
use App\Http\Controllers\IncomingCarController;
use App\Http\Controllers\OutgoingCarController;
use App\Http\Controllers\API\IoTController;
use App\Http\Controllers\API\ANPRController;

/*
|--------------------------------------------------------------------------
| API Routes (Sistem Parkir Sat-Set)
|--------------------------------------------------------------------------
|
| Jalur komunikasi untuk ESP32 dan Python Camera.
| Kita buat terbuka (tanpa auth:sanctum) agar demo lancar tanpa ribet token.
|
*/

// --- 1. JALUR KHUSUS SENSOR & LCD (ESP32) ---

// Update status slot (Full/Empty) dari Sensor IR di dalam parkiran
// Method: POST | URL: http://ip-laptop:8000/api/iot-event
Route::post('/iot-event', [IoTController::class, 'handleEvent']);

// Ambil info sisa slot untuk ditampilkan di LCD Depan
// Method: GET | URL: http://ip-laptop:8000/api/parking-info
Route::get('/parking-info', [IoTController::class, 'getParkingInfo']);

// Device polls for any active commands (OPEN_GATE_EXIT/ENTER) - POST expected from ESP32
Route::post('/get-command', [IoTController::class, 'getCommand']);
Route::post('/consume-command', [IoTController::class, 'consumeCommand']);


// --- 2. JALUR KHUSUS KAMERA & DATA (Python Script) ---

// API untuk ANPR result dari Python (masuk/keluar)
// Method: POST | URL: http://ip-laptop:8000/api/anpr/result
Route::post('/anpr/result', [ANPRController::class, 'storeResult']);


// --- 3. TEST CONNECTION (Opsional) ---
// Buat ngecek apakah HP/ESP32 bisa nyambung ke Laptop
Route::get('/ping', function() {
    return response()->json(['message' => 'Pong! Server is Alive', 'time' => now()]);
});