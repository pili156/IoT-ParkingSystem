<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ParkingSlot;
use App\Models\IncomingCar;
use App\Models\OutgoingCar;

class ParkingSpaceController extends Controller
{
    /**
     * Menampilkan semua slot parkir
     */
    public function index(Request $request)
    {
        $slots = ParkingSlot::all();
        
        return $this->successResponse([
            'slots' => $slots,
            'total_slots' => $slots->count(),
            'available_slots' => $slots->where('status', 'Empty')->count(),
            'occupied_slots' => $slots->where('status', 'Full')->count()
        ], 'Parking slots retrieved successfully');
    }

    /**
     * Menampilkan detail satu slot parkir
     */
    public function show(Request $request, $id)
    {
        $slot = ParkingSlot::find($id);

        if (!$slot) {
            return $this->errorResponse('Parking slot not found', [], 404);
        }

        return $this->successResponse($slot, 'Slot data retrieved successfully');
    }

    /**
     * Memperbarui status slot parkir (digunakan oleh ESP32)
     */
    public function updateStatus(Request $request)
    {
        $request->validate([
            'slot_name' => 'required|string',
            'status' => 'required|in:Empty,Full'
        ]);

        $slot = ParkingSlot::where('slot_name', $request->slot_name)->first();

        if (!$slot) {
            return $this->errorResponse('Parking slot not found', [], 404);
        }

        $oldStatus = $slot->status;
        $slot->update([
            'status' => $request->status
        ]);

        // Cek apakah status berubah dan log perubahan jika perlu
        if ($oldStatus !== $request->status) {
            // Bisa ditambahkan logging di sini jika diperlukan
        }

        return $this->successResponse([
            'slot' => $slot,
            'message' => 'Slot status updated successfully'
        ], 'Slot status updated successfully');
    }

    /**
     * Mendapatkan riwayat masuk kendaraan
     */
    public function getEntries(Request $request)
    {
        $limit = $request->get('limit', 10);
        $entries = IncomingCar::with('slot')->latest()->paginate($limit);

        return $this->paginatedResponse(
            $entries->items(),
            [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
            'Entry records retrieved successfully'
        );
    }

    /**
     * Mendapatkan riwayat keluar kendaraan
     */
    public function getExits(Request $request)
    {
        $limit = $request->get('limit', 10);
        $exits = OutgoingCar::with('incomingCar')->latest()->paginate($limit);

        return $this->paginatedResponse(
            $exits->items(),
            [
                'current_page' => $exits->currentPage(),
                'last_page' => $exits->lastPage(),
                'per_page' => $exits->perPage(),
                'total' => $exits->total(),
            ],
            'Exit records retrieved successfully'
        );
    }

    /**
     * Mendapatkan kendaraan yang sedang parkir (masuk tapi belum keluar)
     */
    public function getCurrentVehicles(Request $request)
    {
        // Dapatkan semua kendaraan yang masuk tapi belum keluar
        $incomingCars = IncomingCar::with('slot')->get();
        
        $currentVehicles = [];
        foreach ($incomingCars as $incoming) {
            // Periksa apakah kendaraan ini sudah keluar atau belum
            $hasExited = OutgoingCar::where('car_no', $incoming->car_no)
                                  ->where('entry_time', $incoming->datetime)
                                  ->exists();
                                  
            if (!$hasExited) {
                $currentVehicles[] = $incoming;
            }
        }

        return $this->successResponse([
            'vehicles' => $currentVehicles,
            'count' => count($currentVehicles),
            'available_slots' => ParkingSlot::where('status', 'Empty')->count(),
            'occupied_slots' => ParkingSlot::where('status', 'Full')->count()
        ], 'Current vehicles and slot status retrieved successfully');
    }
}