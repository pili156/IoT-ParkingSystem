<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\VehicleEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class ANPRController extends Controller
{
    // Python akan POST: { plate, entry_id?, mode: "entry"|"exit", image_base64 }
    public function storeResult(Request $r) {
        $plate = $r->input('plate');
        $mode = $r->input('mode'); // entry | exit
        $entryId = $r->input('entry_id'); // optional, if pre-created
        $img_b64 = $r->input('image_base64');

        // save image
        $imgname = 'plates/'.time().'_'.substr(md5($plate),0,6).'.jpg';
        if ($img_b64) {
            $data = base64_decode($img_b64);
            Storage::disk('public')->put($imgname, $data);
        }

        if ($mode === 'entry') {
            $entry = null;
            if ($entryId) $entry = VehicleEntry::find($entryId);
            if (!$entry) {
                $entry = VehicleEntry::create([
                    'plate'=>$plate,
                    'entry_time'=>Carbon::now(),
                    'entry_image'=>$imgname
                ]);
            } else {
                $entry->plate = $plate;
                $entry->entry_image = $imgname;
                $entry->entry_time = Carbon::now();
                $entry->save();
            }
            // optionally create ESP command to open gate
            return response()->json(['status'=>'ok','entry_id'=>$entry->id]);
        } elseif ($mode === 'exit') {
            // find last open entry with this plate
            $entry = VehicleEntry::where('plate', $plate)->whereNull('exit_time')->latest()->first();
            if (!$entry) return response()->json(['status'=>'not_found'],404);

            $entry->exit_time = Carbon::now();
            $entry->exit_image = $imgname;
            // hitung durasi + tarif sederhana
            $diff = $entry->exit_time->diffInMinutes($entry->entry_time);
            $ratePerHour = 2000; // contoh rupiah / jam
            $amount = ceil($diff/60) * $ratePerHour;
            $entry->amount = $amount;
            $entry->save();

            // create command to open gate for exit
            \App\Models\EspCommand::create(['device_id'=>null,'command'=>'OPEN_GATE']);

            return response()->json(['status'=>'ok','amount'=>$amount,'duration_minutes'=>$diff]);
        }

        return response()->json(['status'=>'bad_request'],400);
    }

    public function listEntries() {
        return VehicleEntry::latest()->take(200)->get();
    }
}
