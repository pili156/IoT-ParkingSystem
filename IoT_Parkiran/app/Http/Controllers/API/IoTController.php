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
        // Support both Laravel-style event_type+slot_name and esp32's `type`+`value` payload
        $data = $request->all();
        $eventType = $request->input('event_type');
        $slotName = $request->input('slot_name');
        $deviceId = $request->input('device_id');

        // If ESP32 style payload is used
        if (!$eventType && $request->has('type')) {
            $eventType = $request->input('type');
            // If esp32 sends `slot_name` inside, use it; otherwise attempt to parse from `value` or fallback to device mapping
            if (!$slotName) {
                $slotName = $request->input('slot_name');
                $value = $request->input('value');
                if (!$slotName && $value) {
                    // Expected formats: Slot-1:0/1 or plain numeric (count). Support Slot-<n>:<state>
                    if (strpos($value, ':') !== false) {
                        [$sName, $sVal] = explode(':', $value, 2);
                        $slotName = $sName;
                        // Keep original event_type, but we will use value to deduce ARRIVAL/DEPARTURE
                        $slotStatusValue = $sVal;
                    }
                }
            }
        }

        // Basic validation
        if (!$eventType) {
            return $this->errorResponse('Invalid event type', [], 422);
        }
        if (!$slotName && !in_array($eventType, ['GateEntry', 'GateExit'])) {
            return $this->errorResponse('slot_name missing for non-gate event', [], 422);
        }

        // Jika event terjadi di gerbang (bukan slot parkir), lakukan logika khusus
        if ($slotName && strpos($slotName, 'Gate') !== false) {
            return $this->handleGateEvent($eventType, $slotName, $deviceId);
        }

        // Cari slot parkir berdasarkan nama
        $slot = ParkingSlot::where('slot_name', $slotName)->first();

        if (!$slot) {
            return $this->errorResponse('Parking slot not found', [], 404);
        }

        // Update status slot berdasarkan event
        if ($eventType === 'ARRIVAL' || $eventType === 'ENTRY') {
            $slot->status = 'Full';
        } elseif ($eventType === 'DEPARTURE') {
            $slot->status = 'Empty';
        } elseif ($eventType === 'SLOT_UPDATE') {
            // For esp32's SLOT_UPDATE, value may be '1' (free/HIGH) or '0' (occupied/LOW)
            $value = $request->input('value');
            if ($value === '1' || $value === 1) {
                $slot->status = 'Empty';
            } elseif ($value === '0' || $value === 0) {
                $slot->status = 'Full';
            }
        }

        $slot->save();

        // If ESP32 requests billing for this slot (e.g., exit request), compute and enqueue command
        if ($eventType === 'EXIT_BILLING_REQUEST') {
            // Find latest incoming car for this slot
            $incoming = IncomingCar::where('slot_name', $slotName)
                ->where('status', 'in')
                ->latest('datetime')
                ->first();

            if ($incoming) {
                // Compute bill similarly to ANPRController
                $entryTime = $incoming->datetime;
                $exitTime = now();
                $totalSeconds = $entryTime->diffInSeconds($exitTime);
                $totalHours = ceil($totalSeconds / 3600);
                $ratePerHour = 5000;
                $bill = $totalHours * $ratePerHour;
                $totalTimeFormatted = $entryTime->diff($exitTime)->format('%H:%I:%S');

                // Create outgoing record
                $outgoing = OutgoingCar::create([
                    'car_no' => $incoming->car_no,
                    'entry_time' => $entryTime,
                    'exit_time' => $exitTime,
                    'total_time' => $totalTimeFormatted,
                    'total_hours' => $totalHours,
                    'bill' => $bill,
                    'slot_name' => $slotName
                ]);

                // Mark incoming status out
                $incoming->update(['status' => 'out']);

                // Free the parking slot
                $slot->update(['status' => 'Empty']);

                // Enqueue command for device with billing info
                EspCommand::create([
                    'command' => 'OPEN_GATE_EXIT',
                    'device_id' => $deviceId,
                    'is_executed' => false,
                    'bill' => $bill,
                    'total_time' => $totalTimeFormatted
                ]);
            }
        }

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
                        'is_executed' => false
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
        } elseif ($slotName === 'GateExit' && ($eventType === 'DEPARTURE' || $eventType === 'EXIT_BILLING_REQUEST')) {
            // Kendaraan ingin keluar - kirim perintah buka gerbang keluar
            // If it's an exit billing request, try to create a billing command with cost/time if slot_name or matching incoming exists
            EspCommand::create([
                'command' => 'OPEN_GATE_EXIT',
                'device_id' => $deviceId,
                'is_executed' => false
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
        $command = EspCommand::where('is_executed', false)
            ->where(function ($query) use ($deviceId) {
                $query->whereNull('device_id')
                      ->orWhere('device_id', $deviceId);
            })
            ->oldest()
            ->first();

        if ($command) {
            // Tandai sebagai sudah diambil (akan ditandai sebagai executed saat ESP32 konfirmasi)
            $command->update(['is_executed' => true]);

            // Prepare data response if there is a bill and total_time
            $data = null;
            if (!is_null($command->bill) || !is_null($command->total_time)) {
                $data = [
                    'cost' => $command->bill,
                    'time' => $command->total_time
                ];
            }

            return $this->successResponse([
                'command' => $command->command,
                'command_id' => $command->id,
                'device_id' => $command->device_id,
                'data' => $data,
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
            'is_executed' => true,
            'execution_result' => $request->result
        ]);

        return $this->successResponse([
            'command_id' => $command->id,
            'is_executed' => true,
            'result' => $request->result
        ], 'Command marked as consumed');
    }
}