<?php

namespace App\Http\Controllers;

use App\Models\ParkingSlot;

class ParkingSlotController extends Controller
{
    public function index()
    {
        // 1. Ambil semua data slot dari database
        $dbSlots = ParkingSlot::all(); 

        // 2. Tentukan jumlah slot fisik yang kamu punya (4 IR Sensor)
        $totalSlots = 4;

        // 3. Siapkan wadah untuk data final
        $slots = collect();

        for ($i = 1; $i <= $totalSlots; $i++) {
            // Nama slot yang kita cari, misal: "Slot-1", "Slot-2", dst.
            // Pastikan format string ini SAMA PERSIS dengan yang dikirim ESP32 (misal: "Slot-1")
            $targetName = "Slot-" . $i; 

            // 4. CARI YANG BENAR (Fix Logic)
            // Cari di koleksi $dbSlots yang kolom 'slot_name'-nya cocok dengan $targetName
            $slot = $dbSlots->firstWhere('slot_name', $targetName);

            if ($slot) {
                // Kalau ketemu di database, pakai datanya (Status Full/Empty asli)
                $slots->push($slot);
            } else {
                // Kalau tidak ketemu, buat data palsu sementara (Status Empty)
                $slots->push((object)[
                    'slot_name' => $targetName,
                    'status' => 'Empty'
                ]);
            }
        }

        return view('parking-slot', compact('slots'));
    }
}