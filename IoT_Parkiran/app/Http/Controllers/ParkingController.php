<?php

namespace App\Http\Controllers;

use App\Models\ParkingSlot;
use App\Models\IncomingCar;
use App\Models\OutgoingCar;

class ParkingController extends Controller
{
    public function parkingSlot()
    {
        $slots = ParkingSlot::all();
        return view('parking-slot', compact('slots'));
    }

    public function incomingCar()
    {
        $incoming = IncomingCar::orderBy('id', 'desc')->get();
        return view('incoming-car', compact('incoming'));
    }

    public function outgoingCar()
    {
        $outgoing = OutgoingCar::orderBy('id', 'desc')->get();
        return view('outgoing-car', compact('outgoing'));
    }
}
