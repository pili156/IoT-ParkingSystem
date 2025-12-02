<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OutgoingCar;

class OutgoingCarController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'car_no' => 'required|string'
        ]);

        $car = OutgoingCar::create([
            'car_no' => $request->car_no
        ]);

        return response()->json([
            'message' => 'Outgoing car recorded',
            'data' => $car
        ], 201);
    }
}
