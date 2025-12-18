<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\IncomingCar;
use App\Models\OutgoingCar;
use App\Models\EspCommand;
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
            'mode' => 'nullable|in:entry,exit',
            'webcam_index' => 'nullable|integer',
            'image_base64' => 'nullable|string',
            'timestamp' => 'nullable|date',
            'slot_name' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', $validator->errors(), 422);
        }

        $plate = trim(strtoupper($request->input('plate')));
        $mode = $request->input('mode');
        $webcamIndex = $request->input('webcam_index');
        $imageBase64 = $request->input('image_base64');

        // If webcam_index is provided, map it to mode: 1 => entry, 2 => exit
        if (!is_null($webcamIndex)) {
            if ($webcamIndex == 1) {
                $mode = 'entry';
            } elseif ($webcamIndex == 2) {
                $mode = 'exit';
            } else {
                return $this->errorResponse('Invalid webcam_index. Supported: 1 (entry), 2 (exit)', [], 422);
            }
        }

        if (empty($mode)) {
            return $this->errorResponse('Mode is required (either `mode` or a valid `webcam_index` must be provided)', [], 422);
        }

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
            return $this->handleEntryMode($plate, $imageName, $request->input('slot_name'));
        } elseif ($mode === 'exit') {
            return $this->handleExitMode($plate, $imageName, $request->input('slot_name'));
        }

        return $this->errorResponse('Invalid mode. Use "entry" or "exit"', [], 400);
    }

    /**
     * Menangani mode ENTRY (kendaraan masuk)
     */
    private function handleEntryMode($plate, $imageName, $slotName = null)
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

        // Slot availability and allocation are handled by ESP32 devices via the IoT API.
        // We will accept the request and store the provided `slot_name` (if any) but will not query or update the ParkingSlot table here.
        $availableSlot = null; // we do not manage slots from API side

        // Buat entri baru (we store the requested `slot_name` when provided)
        $entryData = [
            'car_no' => $plate,
            'datetime' => Carbon::now(),
            'image_path' => $imageName
        ];

        if ($slotName) $entryData['slot_name'] = $slotName;

        $entry = IncomingCar::create($entryData);

        // Kirim perintah buka gerbang MASUK ke ESP32
        EspCommand::create([
            'command' => 'OPEN_GATE_ENTER',
            'device_id' => null, // Untuk semua ESP32 atau bisa disesuaikan
            'is_executed' => false
        ]);

        return $this->successResponse([
            'entry' => $entry,
            'gate_command_sent' => true,
            'assigned_slot' => $slotName ?? null,
            'available_slots' => null,
            'message' => 'Vehicle entry recorded successfully'
        ], 'Vehicle entry recorded successfully');
    }

    /**
     * Menangani mode EXIT (kendaraan keluar)
     */
    private function handleExitMode($plate, $imageName, $slotName = null)
    {
        // Cari data masuk terakhir berdasarkan plat nomor yang belum keluar
        $entry = IncomingCar::where('car_no', $plate)
            ->whereDoesntHave('outgoing', function ($query) {
                $query->whereNotNull('exit_time');
            })
            ->orderBy('datetime', 'desc')
            ->first();

        if (!$entry) {
            // No matching entry found — create an OUTGOING record anyway (unmatched exit)
            $exitTime = Carbon::now();

            $outgoingData = [
                'car_no' => $plate,
                'entry_time' => null,
                'exit_time' => $exitTime,
                'total_time' => null,
                'total_hours' => 0,
                'bill' => 0,
                'image_path' => $imageName
            ];
            if ($slotName) $outgoingData['slot_name'] = $slotName;

            $outgoing = OutgoingCar::create($outgoingData);

            // We don't modify ParkingSlot here — ESP32 controls that.
            $releasedSlot = $outgoing->slot_name ?? null;

            // Still send a gate command for exit, with zero billing for unmatched
            EspCommand::create([
                'command' => 'OPEN_GATE_EXIT',
                'device_id' => null,
                'is_executed' => false,
                'bill' => 0,
                'total_time' => null
            ]);

            return $this->successResponse([
                'outgoing' => $outgoing,
                'bill' => 0,
                'duration_formatted' => null,
                'duration_hours' => 0,
                'gate_command_sent' => true,
                'released_slot' => $releasedSlot,
                'message' => 'Vehicle exit recorded (no matching entry found)'
            ], 'Vehicle exit recorded (no matching entry found)');
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
        $outgoingData = [
            'car_no' => $plate,
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'total_time' => $totalTimeFormatted, // Format HH:MM:SS
            'total_hours' => $totalHours,
            'bill' => $bill,
            'image_path' => $imageName
        ];
        if ($slotName) $outgoingData['slot_name'] = $slotName;

        $outgoing = OutgoingCar::create($outgoingData);

        // Slot status changes (marking a slot empty) are handled by ESP32 via the IoT API.
        // We do not modify the ParkingSlot table here. If the outgoing record contains a slot_name, return it as released_slot.
        $releasedSlot = $outgoing->slot_name ?? null;

        // Kirim perintah buka gerbang KELUAR ke ESP32
        EspCommand::create([
            'command' => 'OPEN_GATE_EXIT',
            'device_id' => null,
            'is_executed' => false,
            'bill' => $bill,
            'total_time' => $totalTimeFormatted
        ]);

        return $this->successResponse([
            'outgoing' => $outgoing,
            'bill' => $bill,
            'duration_formatted' => $totalTimeFormatted,
            'duration_hours' => $totalHours,
            'gate_command_sent' => true,
            'released_slot' => $releasedSlot,
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