<?php

namespace App\Http\Controllers;

use App\Models\ParkingSlot;
use App\Models\VehicleEntry;

class DashboardController extends Controller
{
    public function index()
    {
        return view('dashboard', [
            'vehicles' => VehicleEntry::all(),
            'active' => VehicleEntry::whereNull('time_out')->count(),
            'income' => VehicleEntry::sum('fee')
        ]);
    }

    public function slots()
    {
        return view('slots', [
            'slots' => ParkingSlot::all()
        ]);
    }

    public function vehicles()
    {
        return view('vehicles', [
            'vehicles' => VehicleEntry::latest()->get()
        ]);
    }
}
