<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\ParkingSlot;
use App\Models\EspCommand;
use App\Models\VehicleEntry;
use Carbon\Carbon;

class IoTController extends Controller
{
    // ESP32 mengirim: { device_id, event_type: "ARRIVAL"|"DEPARTURE", slot_id }
    public function event(Request $r) {
        $device = $r->input('device_id');
        $type = $r->input('event_type');
        $slotId = $r->input('slot_id');

        if ($type === 'ARRIVAL') {
            // tandai slot occupied = true
            if ($slotId) {
                $slot = ParkingSlot::find($slotId);
                if ($slot) {
                    $slot->occupied = true;
                    $slot->save();
                }
            }
            // buat entri placeholder — Python ANPR akan mengisi plate later via /anpr/result
            $entry = VehicleEntry::create([
                'slot_id'=>$slotId,
                'entry_time'=>Carbon::now()
            ]);

            // jawab sukses
            return response()->json(['status'=>'ok','entry_id'=>$entry->id]);
        }

        if ($type === 'DEPARTURE') {
            // tandai slot occupied = false
            if ($slotId) {
                $slot = ParkingSlot::find($slotId);
                if ($slot) {
                    $slot->occupied = false;
                    $slot->save();
                }
            }
            // return ok
            return response()->json(['status'=>'ok']);
        }

        return response()->json(['status'=>'unknown event'], 400);
    }

    // ESP polls untuk perintah (example)
    public function getCommand(Request $r) {
        $device = $r->input('device_id');
        $cmd = EspCommand::where('consumed', false)->latest()->first();
        if ($cmd) {
            // mark consumed
            $cmd->consumed = true;
            $cmd->save();
            return response()->json(['command'=>$cmd->command]);
        }
        return response()->json(['command'=>null]);
    }
}
