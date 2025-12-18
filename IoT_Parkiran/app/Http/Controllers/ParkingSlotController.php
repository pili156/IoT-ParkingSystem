<?php

namespace App\Http\Controllers;

class ParkingSlotController extends Controller
{
    public function index()
    {
        // ParkingSlot state is managed exclusively by ESP32 devices.
        // We do NOT read or write the ParkingSlot table from here per project policy.

        // Define the number of physical slots (4 IR sensors)
        $totalSlots = 4;

        $slots = collect();
        for ($i = 1; $i <= $totalSlots; $i++) {
            $targetName = "Slot-" . $i;
            $slots->push((object)[
                'slot_name' => $targetName,
                'status' => 'Managed by ESP32'
            ]);
        }

        return view('parking-slot', compact('slots'));
    }
}