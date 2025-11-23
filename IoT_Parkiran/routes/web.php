<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;

// Hapus duplicated route '/'
// TAMPILAN LOGIN
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'doLogin'])->name('doLogin');

// Dashboard
Route::middleware('auth')->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/slots', [DashboardController::class, 'slots'])->name('slots');
    Route::get('/vehicles', [DashboardController::class, 'vehicles'])->name('vehicles');

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
