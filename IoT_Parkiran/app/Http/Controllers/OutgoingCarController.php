<?php
namespace App\Http\Controllers;
use App\Models\OutgoingCar; // Pakai model yang benar

class OutgoingCarController extends Controller
{
    public function index()
    {
        // Ambil data dari tabel outgoing_cars
        $outgoing = OutgoingCar::orderBy('exit_time', 'desc')->get();
        return view('outgoing-car', compact('outgoing'));
    }
}