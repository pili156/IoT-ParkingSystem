<?php

use App\Http\Controllers\ParkingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function() {
    return redirect('/parking-slot');
});

Route::get('/parking-slot', [ParkingController::class, 'parkingSlot']);
Route::get('/incoming-car', [ParkingController::class, 'incomingCar']);
Route::get('/outgoing-car', [ParkingController::class, 'outgoingCar']);