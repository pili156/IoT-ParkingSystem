<?php

namespace App\Http\Controllers;

use App\Models\ParkingSlot;

class ParkingSlotController extends Controller
{
    public function index()
    {
        // Fetch the actual parking slot data from the database
        // This connects with the real status managed by ESP32 devices
        $slots = ParkingSlot::orderBy('slot_name')->get();

        return view('parking-slot', compact('slots'));
    }
}