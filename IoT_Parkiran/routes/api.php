<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ANPRController;
use App\Http\Controllers\IoTController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Jalur ini bebas dari CSRF Token. Cocok untuk komunikasi antar mesin
| (Python Script & ESP32).
|
*/

// 1. Jalur untuk Python (Kamera ANPR)
// Python akan menembak ke: http://ip-laptop-kamu/api/anpr-result
Route::post('/anpr-result', [ANPRController::class, 'storeResult']);

// 2. Jalur untuk ESP32 (Sensor IR)
// ESP32 lapor ada mobil lewat: http://ip-laptop-kamu/api/iot-event
Route::post('/iot-event', [IoTController::class, 'event']);

// 3. Jalur untuk ESP32 (Cek Perintah Buka Gerbang)
// ESP32 nanya "Boleh buka gerbang gak?": http://ip-laptop-kamu/api/get-command
Route::get('/get-command', [IoTController::class, 'getCommand']);