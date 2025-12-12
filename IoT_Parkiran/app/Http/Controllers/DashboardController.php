<?php

namespace App\Http\Controllers;

use App\Models\IncomingCar;
use App\Models\OutgoingCar;
use App\Models\ParkingSlot;

class DashboardController extends Controller
{
    public function index()
    {
        // Hitung Pendapatan (Total bill dari tabel Outgoing)
        $income = OutgoingCar::sum('bill');

        // Hitung Kendaraan Aktif (Masuk tapi belum ada di tabel Keluar)
        // Logika sederhana: Total Masuk - Total Keluar
        $totalMasuk = IncomingCar::count();
        $totalKeluar = OutgoingCar::count();
        $activeVehicles = $totalMasuk - $totalKeluar;
        if($activeVehicles < 0) $activeVehicles = 0; // Jaga-jaga biar gak minus

        return view('dashboard', [
            // Kirim 5 data terakhir keluar untuk tabel log
            'vehicles' => OutgoingCar::latest('exit_time')->take(5)->get(),
            'active' => $activeVehicles,
            'income' => $income,
            // Hitung slot kosong (Misal total slot ada 4)
            'empty_slots' => 4 - $activeVehicles
        ]);
    }
}