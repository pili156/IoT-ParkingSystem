<?php

namespace App\Http\Controllers;

use App\Models\ParkingSlot;
use Illuminate\Http\Request;

class ParkingSlotController extends Controller
{
    /**
     * Menampilkan halaman Parking Slots (Status Real-Time).
     * Diambil dari 'slots()' milik DashboardController temanmu.
     */
    public function index()
    {
        // Ambil semua data slot parkir
        $slots = ParkingSlot::all();

        // Tampilkan view 'parking-slot'
        // Catatan: Pastikan kamu sudah memiliki view ini di resources/views/parking-slot.blade.php
        return view('parking-slot', compact('slots'));
    }
}