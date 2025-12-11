<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ParkingSlot;
use App\Models\EspCommand;
use App\Models\IncomingCar;
use App\Models\OutgoingCar;
use Illuminate\Support\Facades\Validator;

class IoTController extends Controller
{
    /**
     * ESP32 melaporkan event (sensor mendeteksi perubahan di slot atau gerbang)
     */
    public function handleEvent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'event_type' => 'required|in:ARRIVAL,DEPARTURE',
            'slot_name' => 'required|string',
            'device_id' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', $validator->errors(), 422);
        }

        $eventType = $request->input('event_type');
        $slotName = $request->input('slot_name');
        $deviceId = $request->input('device_id');

        // Jika event terjadi di gerbang (bukan slot parkir), lakukan logika khusus
        if (strpos($slotName, 'Gate') !== false) {
            return $this->handleGateEvent($eventType, $slotName, $deviceId);
        }

        // Cari slot parkir berdasarkan nama
        $slot = ParkingSlot::where('slot_name', $slotName)->first();

        if (!$slot) {
            return $this->errorResponse('Parking slot not found', [], 404);
        }

        // Update status slot berdasarkan event
        if ($eventType === 'ARRIVAL') {
            $slot->status = 'Full';
        } elseif ($eventType === 'DEPARTURE') {
            $slot->status = 'Empty';
        }

        $slot->save();

        return $this->successResponse([
            'slot' => $slot,
            'event_type' => $eventType,
            'device_id' => $deviceId,
            'timestamp' => now()->toISOString()
        ], 'Slot event processed successfully');
    }

    /**
     * Menangani event khusus gerbang (masuk/keluar)
     */
    private function handleGateEvent($eventType, $slotName, $deviceId)
    {
        if ($slotName === 'GateEntry' && $eventType === 'ARRIVAL') {
            // Kendaraan ingin masuk - cek apakah ada slot tersedia
            $availableSlots = ParkingSlot::where('status', 'Empty')->count();
            
            if ($availableSlots > 0) {
                // Kirim perintah buka gerbang masuk
                EspCommand::create([
                    'command' => 'OPEN_GATE_ENTER',
                    'device_id' => $deviceId,
                    'consumed' => false
                ]);

                return $this->successResponse([
                    'event_type' => $eventType,
                    'slot_name' => $slotName,
                    'command_sent' => 'OPEN_GATE_ENTER',
                    'available_slots' => $availableSlots,
                    'message' => 'Vehicle entry allowed, gate will open'
                ], 'Entry event processed, gate command sent');
            } else {
                return $this->errorResponse('No available parking slots', [], 409);
            }
        } elseif ($slotName === 'GateExit' && $eventType === 'DEPARTURE') {
            // Kendaraan ingin keluar - kirim perintah buka gerbang keluar
            EspCommand::create([
                'command' => 'OPEN_GATE_EXIT',
                'device_id' => $deviceId,
                'consumed' => false
            ]);

            return $this->successResponse([
                'event_type' => $eventType,
                'slot_name' => $slotName,
                'command_sent' => 'OPEN_GATE_EXIT',
                'message' => 'Vehicle exit allowed, gate will open'
            ], 'Exit event processed, gate command sent');
        }

        return $this->errorResponse('Invalid gate event', [], 400);
    }

    /**
     * ESP32 meminta perintah (cek apakah ada perintah buka gerbang)
     */
    public function getCommand(Request $request)
    {
        $deviceId = $request->input('device_id');

        // Ambil perintah yang belum dijalankan untuk device ini atau untuk semua device
        $command = EspCommand::where('consumed', false)
            ->where(function ($query) use ($deviceId) {
                $query->whereNull('device_id')
                      ->orWhere('device_id', $deviceId);
            })
            ->oldest()
            ->first();

        if ($command) {
            // Tandai sebagai sudah diambil (akan ditandai sebagai executed saat ESP32 konfirmasi)
            $command->update(['consumed' => true]);

            return $this->successResponse([
                'command' => $command->command,
                'command_id' => $command->id,
                'device_id' => $command->device_id,
                'timestamp' => now()->toISOString()
            ], 'Command retrieved successfully');
        }

        return $this->successResponse([
            'command' => 'WAIT',
            'command_id' => null,
            'device_id' => null,
            'timestamp' => now()->toISOString()
        ], 'No active commands');
    }

    /**
     * ESP32 melaporkan bahwa perintah telah dijalankan
     */
    public function consumeCommand(Request $request)
    {
        $request->validate([
            'command_id' => 'required|integer|exists:esp_commands,id',
            'result' => 'nullable|string',
            'device_id' => 'nullable|string'
        ]);

        $command = EspCommand::find($request->command_id);

        if (!$command) {
            return $this->errorResponse('Command not found', [], 404);
        }

        $command->update([
            'consumed' => true,
            'execution_result' => $request->result,
            'executed_at' => now()
        ]);

        return $this->successResponse([
            'command_id' => $command->id,
            'consumed' => true,
            'result' => $request->result
        ], 'Command marked as consumed');
    }
}