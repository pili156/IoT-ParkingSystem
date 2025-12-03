<?php

namespace App\Http\Controllers;

use App\Models\ParkingSlot;
use App\Models\VehicleEntry;

class DashboardController extends Controller
{
    /**
     * Menampilkan ringkasan dashboard.
     * Menggunakan VehicleEntry untuk data ringkasan.
     */
    public function index()
    {
        return view('dashboard', [
            // Ambil semua log transaksi
            'vehicles' => VehicleEntry::all(), 
            // Hitung kendaraan yang masih di dalam (entry_time ada, exit_time NULL)
            'active' => VehicleEntry::whereNotNull('entry_time')->whereNull('exit_time')->count(), 
            // Hitung total pendapatan (kolom 'amount' di VehicleEntry = fee/bill)
            'income' => VehicleEntry::sum('amount') 
        ]);
    }

    // Method 'slots' dan 'vehicles' dihapus karena sudah diurus oleh ParkingSlotController & IncomingCarController
}