<?php

use Illuminate\Support\Facades\Route;
// Import Controller yang baru kita buat
use App\Http\Controllers\ParkingSlotController;
use App\Http\Controllers\IncomingCarController;
use App\Http\Controllers\OutgoingCarController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Ini adalah jalur untuk halaman website yang dilihat oleh manusia (Admin).
|
*/

Route::get('/', function () {
    // Redirect langsung ke halaman dashboard slot
    return redirect('/parking-slot');
});

// Halaman Monitoring Slot (Real-time)
Route::get('/parking-slot', [ParkingSlotController::class, 'index'])->name('parking.slots');

// Halaman Data Masuk (Incoming)
Route::get('/incoming-car', [IncomingCarController::class, 'index'])->name('parking.incoming');

// Halaman Data Keluar & Tagihan (Outgoing)
Route::get('/outgoing-car', [OutgoingCarController::class, 'index'])->name('parking.outgoing');