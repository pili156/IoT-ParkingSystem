<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ParkingSlotController;
use App\Http\Controllers\Api\VehicleController;

Route::get('/test', fn() => response()->json(['message' => 'API Ready!']));

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/slot/update', [ParkingSlotController::class, 'updateStatus']);

    Route::post('/entry', [VehicleController::class, 'registerEntry']);
    Route::post('/exit', [VehicleController::class, 'registerExit']);
});
