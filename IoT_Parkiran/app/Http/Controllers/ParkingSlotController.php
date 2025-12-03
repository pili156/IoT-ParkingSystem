<?php

namespace App\Http\Controllers;

use App\Models\ParkingSlot;

class ParkingSlotController extends Controller
{
    public function index()
    {
        // Ambil data dari database
        $dbSlots = ParkingSlot::orderBy('slot_name', 'asc')->get();

        // Jumlah slot yang ingin ditampilkan
        $totalSlots = 4;

        // Jika jumlah data di database kurang dari 4 → tambahkan slot kosong
        $slots = collect();

        for ($i = 1; $i <= $totalSlots; $i++) {
            // Cek apakah slot ke-i ada di database
            $slot = $dbSlots->get($i - 1);

            if ($slot) {
                // Kalau ada → pakai data database
                $slots->push($slot);
            } else {
                // Kalau tidak ada → buat default EMPTY
                $slots->push((object)[
                    'slot_name' => "Slot $i",
                    'status' => 'Empty'
                ]);
            }
        }

        return view('parking-slot', compact('slots'));
    }
}
