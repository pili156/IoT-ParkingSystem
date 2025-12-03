<?php
namespace App\Http\Controllers;
use App\Models\IncomingCar; // Pakai model yang benar

class IncomingCarController extends Controller
{
    public function index()
    {
        // Ambil data dari tabel incoming_cars
        $incoming = IncomingCar::orderBy('datetime', 'desc')->get();
        return view('incoming-car', compact('incoming'));
    }
}