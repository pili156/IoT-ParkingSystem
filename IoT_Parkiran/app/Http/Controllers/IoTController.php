<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\ParkingSlot;
use App\Models\EspCommand;

class IoTController extends Controller
{
    // Dipanggil ESP32 saat sensor IR mendeteksi perubahan
    public function event(Request $r) {
        $type = $r->input('event_type'); // ARRIVAL | DEPARTURE
        $slotName = $r->input('slot_name'); // Misal: "A1" (sesuaikan dengan kolom slot_name di DB)

        if ($slotName) {
            // Cari slot berdasarkan nama
            $slot = ParkingSlot::where('slot_name', $slotName)->first();

            if ($slot) {
                if ($type === 'ARRIVAL') {
                    $slot->status = 'Full'; // Update jadi penuh
                } elseif ($type === 'DEPARTURE') {
                    $slot->status = 'Empty'; // Update jadi kosong
                }
                $slot->save();
                
                return response()->json(['status' => 'ok', 'slot' => $slotName, 'new_status' => $slot->status]);
            }
        }
        
        return response()->json(['status' => 'slot not found'], 404);
    }

    // Dipanggil ESP32 terus menerus untuk cek apakah ada perintah buka gerbang
    public function getCommand(Request $r) {
        // Ambil perintah yang belum dijalankan (consumed = 0/false)
        $cmd = EspCommand::where('consumed', false)->oldest()->first();
        
        if ($cmd) {
            $cmd->consumed = true; // Tandai sudah diambil
            $cmd->save();
            return response()->json(['command' => $cmd->command]);
        }
        return response()->json(['command' => 'WAIT']);
    }
}