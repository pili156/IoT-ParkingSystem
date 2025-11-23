<?php

use App\Http\Controllers\IoTController;
use App\Http\Controllers\ANPRController;
use Illuminate\Support\Facades\Route;

Route::post('/esp/event',  [IoTController::class, 'event']);
Route::post('/esp/command',[IoTController::class, 'getCommand']);

// Python ANPR → Laravel
Route::post('/anpr/result', [ANPRController::class, 'storeResult']);