<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\IncomingCar;
use App\Models\OutgoingCar;
use App\Models\EspCommand;
use App\Models\ParkingSlot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ANPRController extends Controller
{
    /**
     * Menyimpan hasil ANPR dari Python script
     */
    public function storeResult(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plate' => 'required|string|max:20',
            'mode' => 'required|in:entry,exit',
            'image_base64' => 'nullable|string',
            'timestamp' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', $validator->errors(), 422);
        }

        $plate = trim(strtoupper($request->input('plate')));
        $mode = $request->input('mode');
        $imageBase64 = $request->input('image_base64');

        // Simpan gambar jika tersedia
        $imageName = null;
        if ($imageBase64) {
            $imageData = base64_decode($imageBase64);
            if ($imageData !== false) {
                $imageName = 'plates/' . time() . '_' . substr(md5($plate), 0, 6) . '.jpg';
                Storage::disk('public')->put($imageName, $imageData);
            }
        }

        if ($mode === 'entry') {
            return $this->handleEntryMode($plate, $imageName);
        } elseif ($mode === 'exit') {
            return $this->handleExitMode($plate, $imageName);
        }

        return $this->errorResponse('Invalid mode. Use "entry" or "exit"', [], 400);
    }

    /**
     * Menangani mode ENTRY (kendaraan masuk)
     */
    private function handleEntryMode($plate, $imageName)
    {
        // Cek apakah kendaraan sudah dalam tempat parkir
        $existingEntry = IncomingCar::where('car_no', $plate)
            ->whereDoesntHave('outgoing', function ($query) {
                $query->whereNotNull('exit_time');
            })
            ->latest()
            ->first();

        if ($existingEntry) {
            return $this->errorResponse('Vehicle is already parked in the facility', [], 409);
        }

        // Cek apakah masih ada slot tersedia
        $availableSlotCount = ParkingSlot::where('status', 'Empty')->count();
        if ($availableSlotCount <= 0) {
            return $this->errorResponse('No available parking slots', [], 409);
        }

        // Ambil slot kosong pertama
        $availableSlot = ParkingSlot::where('status', 'Empty')->first();

        if ($availableSlot) {
            // Update status slot menjadi penuh
            $availableSlot->update(['status' => 'Full']);
        }

        // Buat entri baru
        $entry = IncomingCar::create([
            'car_no' => $plate,
            'datetime' => Carbon::now(),
            'image_path' => $imageName
        ]);

        // Kirim perintah buka gerbang MASUK ke ESP32
        EspCommand::create([
            'command' => 'OPEN_GATE_ENTER',
            'device_id' => null, // Untuk semua ESP32 atau bisa disesuaikan
            'consumed' => false
        ]);

        return $this->successResponse([
            'entry' => $entry,
            'gate_command_sent' => true,
            'assigned_slot' => $availableSlot ? $availableSlot->slot_name : null,
            'available_slots' => $availableSlotCount - 1,
            'message' => 'Vehicle entry recorded successfully'
        ], 'Vehicle entry recorded successfully');
    }

    /**
     * Menangani mode EXIT (kendaraan keluar)
     */
    private function handleExitMode($plate, $imageName)
    {
        // Cari data masuk terakhir berdasarkan plat nomor yang belum keluar
        $entry = IncomingCar::where('car_no', $plate)
            ->whereDoesntHave('outgoing', function ($query) {
                $query->whereNotNull('exit_time');
            })
            ->orderBy('datetime', 'desc')
            ->first();

        if (!$entry) {
            return $this->errorResponse('Entry record not found for this vehicle', [], 404);
        }

        // Hitung durasi dan biaya
        $entryTime = Carbon::parse($entry->datetime);
        $exitTime = Carbon::now();

        // Hitung durasi dalam format: jam:menit:detik
        $duration = $entryTime->diff($exitTime);
        $totalTimeFormatted = $duration->format('%H:%I:%S'); // Format: HH:MM:SS

        // Hitung jumlah jam pembulatan ke atas (untuk tarif)
        $totalSeconds = $entryTime->diffInSeconds($exitTime);
        $totalHours = ceil($totalSeconds / 3600); // Konversi detik ke jam dan bulatkan ke atas

        // Hitung biaya (misalnya Rp 5000 per jam)
        $ratePerHour = 5000;
        $bill = $totalHours * $ratePerHour;

        // Simpan ke tabel outgoing_cars
        $outgoing = OutgoingCar::create([
            'car_no' => $plate,
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'total_time' => $totalTimeFormatted, // Format HH:MM:SS
            'total_hours' => $totalHours,
            'bill' => $bill,
            'image_path' => $imageName
        ]);

        // Update status slot parkir menjadi kosong
        // Kita bisa mencocokkan dengan slot yang digunakan saat masuk jika ada informasi itu
        // Untuk sementara, ambil slot yang terisi terakhir (bisa disesuaikan dengan logika bisnis)
        $occupiedSlot = ParkingSlot::where('status', 'Full')->first();
        if ($occupiedSlot) {
            $occupiedSlot->update(['status' => 'Empty']);
        }

        // Kirim perintah buka gerbang KELUAR ke ESP32
        EspCommand::create([
            'command' => 'OPEN_GATE_EXIT',
            'device_id' => null,
            'consumed' => false
        ]);

        return $this->successResponse([
            'outgoing' => $outgoing,
            'bill' => $bill,
            'duration_formatted' => $totalTimeFormatted,
            'duration_hours' => $totalHours,
            'gate_command_sent' => true,
            'released_slot' => $occupiedSlot ? $occupiedSlot->slot_name : null,
            'message' => 'Vehicle exit recorded successfully'
        ], 'Vehicle exit recorded successfully');
    }

    /**
     * Mendapatkan riwayat hasil ANPR
     */
    public function getResults(Request $request)
    {
        $limit = $request->get('limit', 10);
        $filter = $request->get('filter', 'all'); // 'all', 'entry', 'exit'

        $query = IncomingCar::with(['outgoing']);

        if ($filter === 'entry') {
            $query = IncomingCar::with(['outgoing']);
            $query->orderBy('datetime', 'desc');
        } elseif ($filter === 'exit') {
            $query = OutgoingCar::with(['incomingCar']);
            $query->orderBy('exit_time', 'desc');
        } else {
            // For 'all', we show incoming records with their exit info if available
            $query->orderBy('datetime', 'desc');
        }

        $results = $query->paginate($limit);

        return $this->paginatedResponse(
            $results->items(),
            [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
            ],
            'ANPR results retrieved successfully'
        );
    }
}