<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\IncomingCar;
use App\Models\OutgoingCar;
use App\Models\EspCommand;
use App\Models\ParkingSlot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class ANPRController extends Controller
{
    /**
     * Handle ANPR result dari Python ANPR API
     * Menerima: plate, webcam_index (1=masuk, 2=keluar)
     * Response: simple JSON
     */
    public function storeResult(Request $r)
    {
        // Validasi input
        $r->validate([
            'plate' => 'required|string',
            'webcam_index' => 'required|integer|in:1,2',
            'image_base64' => 'nullable|string',
            'timestamp' => 'nullable|numeric',
            'slot_name' => 'nullable|string'
        ]);

        $plate = strtoupper(str_replace(' ', '', $r->input('plate'))); // Format: BA3242CD (no space)
        $webcamIndex = $r->input('webcam_index'); // 1 = entry, 2 = exit
        $imgB64 = $r->input('image_base64');
        $timestamp = $r->input('timestamp', microtime(true));

        try {
            // Jika webcam index = 1, simpan ke incoming_cars
            if ($webcamIndex === 1) {
                return $this->handleIncomingCar($plate, $imgB64, $timestamp, $r->input('slot_name'));
            }

            // Jika webcam index = 2, simpan ke outgoing_cars & hitung biaya
            if ($webcamIndex === 2) {
                return $this->handleOutgoingCar($plate, $imgB64, $timestamp, $r->input('slot_name'));
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }

        return response()->json(['success' => false, 'message' => 'Invalid webcam_index'], 400);
    }

    /**
     * Handle Incoming Car (Webcam Index = 1)
     */
    private function handleIncomingCar($plate, $imgB64, $timestamp, $slotName = null)
    {
        // Cek apakah plat sudah ada dengan status 'in'
        $existing = IncomingCar::where('car_no', $plate)
                               ->where('status', 'in')
                               ->latest()
                               ->first();

        if ($existing) {
            // Update record yang existing dengan timestamp terbaru
            $imgPath = null;
            if ($imgB64) {
                $imgPath = 'plates/in_' . time() . '_' . $plate . '.jpg';
                Storage::disk('public')->put($imgPath, base64_decode($imgB64));
            }

            $existing->update([
                'datetime' => Carbon::createFromTimestamp($timestamp),
                'image_path' => $imgPath ?? $existing->image_path
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Incoming car updated',
                'car_no' => $plate
            ], 200);
        }

        // Create new record jika tidak ada
        $imgPath = null;
        if ($imgB64) {
            $imgPath = 'plates/in_' . time() . '_' . $plate . '.jpg';
            Storage::disk('public')->put($imgPath, base64_decode($imgB64));
        }

        $carData = [
            'car_no' => $plate,
            'datetime' => Carbon::createFromTimestamp($timestamp),
            'image_path' => $imgPath,
            'status' => 'in'
        ];
        if ($slotName) $carData['slot_name'] = $slotName;

        $car = IncomingCar::create($carData);

        // If slot provided, mark it full
        if ($slotName) {
            $pSlot = ParkingSlot::where('slot_name', $slotName)->first();
            if ($pSlot) $pSlot->update(['status' => 'Full']);
        }

        // Trigger buka gerbang masuk (ESP32)
        EspCommand::create(['command' => 'OPEN_GATE_ENTER', 'is_executed' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Incoming car registered',
            'car_no' => $plate
        ], 201);
    }

    /**
     * Handle Outgoing Car (Webcam Index = 2)
     */
    private function handleOutgoingCar($plate, $imgB64, $timestamp, $slotName = null)
    {
        // Cari data masuk terakhir
        $entry = IncomingCar::where('car_no', $plate)
                            ->where('status', 'in')
                            ->latest('datetime')
                            ->first();

        if (!$entry) {
            return response()->json([
                'success' => false,
                'message' => 'No incoming car record found'
            ], 404);
        }

        // Hitung durasi & biaya
        $entryTime = $entry->datetime;
        $exitTime = Carbon::createFromTimestamp($timestamp);

        $durationDiff = $entryTime->diff($exitTime);
        $totalTimeFormatted = $durationDiff->format('%H:%I:%S');
        $totalSeconds = $entryTime->diffInSeconds($exitTime);
        $totalHours = ceil($totalSeconds / 3600);

        $ratePerHour = 5000; // Rp 5000 per jam
        $bill = $totalHours * $ratePerHour;

        // Simpan gambar
        $imgPath = null;
        if ($imgB64) {
            $imgPath = 'plates/out_' . time() . '_' . $plate . '.jpg';
            Storage::disk('public')->put($imgPath, base64_decode($imgB64));
        }

        // Cek apakah sudah ada outgoing car untuk plat ini
        $existingOutgoing = OutgoingCar::where('car_no', $plate)
                                       ->whereNull('exit_time')
                                       ->first();

        if ($existingOutgoing) {
            // Update yang ada
            $existingOutgoing->update([
                'exit_time' => $exitTime,
                'total_time' => $totalTimeFormatted,
                'total_hours' => $totalHours,
                'bill' => $bill,
                'image_path' => $imgPath ?? $existingOutgoing->image_path
            ]);

            // Update incoming car status
            $entry->update(['status' => 'out']);

            // Update parking slot to empty if slot_name exists on the incoming record
            if (!empty($entry->slot_name)) {
                $pSlot = ParkingSlot::where('slot_name', $entry->slot_name)->first();
                if ($pSlot) $pSlot->update(['status' => 'Empty']);
            }

            EspCommand::create(['command' => 'OPEN_GATE_EXIT']);

            return response()->json([
                'success' => true,
                'message' => 'Outgoing car updated',
                'car_no' => $plate,
                'bill' => $bill
            ], 200);
        }

        // Create new outgoing record
        $outgoingData = [
            'car_no' => $plate,
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'total_time' => $totalTimeFormatted,
            'total_hours' => $totalHours,
            'bill' => $bill,
            'image_path' => $imgPath
        ];
        if ($slotName) $outgoingData['slot_name'] = $slotName;

        $outgoing = OutgoingCar::create($outgoingData);

        // Update incoming car status
        $entry->update(['status' => 'out']);

        // Update parking slot to empty based on slot_name if available
        if (!empty($outgoing->slot_name)) {
            $pSlot = ParkingSlot::where('slot_name', $outgoing->slot_name)->first();
            if ($pSlot) $pSlot->update(['status' => 'Empty']);
        }

        // Trigger buka gerbang keluar (ESP32)
        EspCommand::create(['command' => 'OPEN_GATE_EXIT', 'is_executed' => false, 'bill' => $bill, 'total_time' => $totalTimeFormatted]);

        return response()->json([
            'success' => true,
            'message' => 'Outgoing car registered',
            'car_no' => $plate,
            'bill' => $bill
        ], 201);
    }
}