<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ParkingSlot;

class IoTController extends Controller
{
    // API 1: Update Status Slot (Dipanggil sensor per slot)
    // Route: POST /api/iot-event
    public function event(Request $r) {
        $type = $r->input('event_type'); // 'ARRIVAL' (Masuk Slot) | 'DEPARTURE' (Keluar Slot)
        $slotName = $r->input('slot_name'); 

        $slot = ParkingSlot::where('slot_name', $slotName)->first();

        if ($slot) {
            // Update status slot
            $slot->status = ($type === 'ARRIVAL') ? 'Full' : 'Empty';
            $slot->save();
            
            return response()->json([
                'status' => 'ok', 
                'slot' => $slotName, 
                'new_status' => $slot->status
            ]);
        }
        
        return response()->json(['status' => 'slot not found'], 404);
    }

    // API 2: Info Slot (Dipanggil ESP32 buat LCD Depan)
    // Route: GET /api/parking-info
    public function getParkingInfo() {
        // Hitung slot yang statusnya 'Empty'
        $freeSlots = ParkingSlot::where('status', 'Empty')->count();
        
        // Asumsi total slot ada 4 (sesuaikan kalau beda)
        $totalSlots = 4;

        return response()->json([
            'free_slots' => $freeSlots,
            'total_slots' => $totalSlots,
            'message' => "Available: $freeSlots / $totalSlots"
        ]);
    }
}