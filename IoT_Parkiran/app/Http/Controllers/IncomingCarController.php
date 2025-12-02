<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;


class IncomingCar extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'car_no' => 'required|string'
        ]);

        $car = \App\Models\IncomingCar::create([
            'car_no' => $request->car_no
        ]);

        return response()->json([
            'message' => 'Incoming car recorded',
            'data' => $car
        ], 201);
    }
}
