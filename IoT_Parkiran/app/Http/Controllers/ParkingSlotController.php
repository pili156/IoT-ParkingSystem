<?php

namespace App\Http\Controllers;

use App\Models\ParkingSlot;
use Illuminate\Http\Request;

class ParkingSlotController extends Controller
{
    /**
     * Menampilkan halaman Parking Slots (Status Real-Time).
     */
    public function index()
    {
        // 1. Ambil data slot dan urutkan abjad (A1, A2, dst)
        $slots = ParkingSlot::orderBy('slot_name', 'asc')->get();

        // 2. KOREKSI PENTING:
        // Karena file view ada di 'resources/views/parking-slot.blade.php' (bukan di dalam folder screens),
        // Hapus kata 'screens.' di depannya.
        return view('parking-slot', compact('slots'));
    }
}