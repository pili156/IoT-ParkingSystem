<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Import Controller yang SUDAH kita perbaiki tadi
use App\Http\Controllers\IncomingCarController;
use App\Http\Controllers\OutgoingCarController;
use App\Http\Controllers\IoTController;

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
Route::post('/iot-event', [IoTController::class, 'event']);

// Ambil info sisa slot untuk ditampilkan di LCD Depan
// Method: GET | URL: http://ip-laptop:8000/api/parking-info
Route::get('/parking-info', [IoTController::class, 'getParkingInfo']);


// --- 2. JALUR KHUSUS KAMERA & DATA (Python Script) ---

// Simpan data mobil MASUK (Foto & Waktu)
// Method: POST | URL: http://ip-laptop:8000/api/incoming-car
Route::post('/incoming-car', [IncomingCarController::class, 'store']);

// Simpan data mobil KELUAR (Foto, Waktu, & Hitung Duit)
// Method: POST | URL: http://ip-laptop:8000/api/outgoing-car
Route::post('/outgoing-car', [OutgoingCarController::class, 'store']);


// --- 3. TEST CONNECTION (Opsional) ---
// Buat ngecek apakah HP/ESP32 bisa nyambung ke Laptop
Route::get('/ping', function() {
    return response()->json(['message' => 'Pong! Server is Alive', 'time' => now()]);
});