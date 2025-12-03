<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\IncomingCar; // Panggil Model yang sesuai tabel kamu
use App\Models\OutgoingCar; // Panggil Model yang sesuai tabel kamu
use App\Models\EspCommand;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class ANPRController extends Controller
{
    public function storeResult(Request $r) {
        $plate = $r->input('plate');
        $mode = $r->input('mode'); // entry | exit
        $img_b64 = $r->input('image_base64'); 

        // Simpan gambar (Logic ini sudah oke)
        $imgname = null;
        if ($img_b64) {
            $imgname = 'plates/' . time() . '_' . substr(md5($plate), 0, 6) . '.jpg';
            Storage::disk('public')->put($imgname, base64_decode($img_b64));
        }

        if ($mode === 'entry') {
            // LOGIKA MASUK: Simpan ke tabel incoming_cars
            // Sesuai jurnal
            
            IncomingCar::create([
                'car_no'    => $plate,
                'datetime'  => Carbon::now(), // Sesuai nama kolom di DB kamu
                // 'image'  => $imgname // Kalau di DB ada kolom image, aktifkan ini
            ]);
            
            // Kirim perintah buka gerbang MASUK ke ESP32
            EspCommand::create(['command' => 'OPEN_GATE_ENTER']); 
            
            return response()->json(['status' => 'ok', 'message' => 'Mobil Masuk Tercatat']);
            
        } elseif ($mode === 'exit') {
            // LOGIKA KELUAR: Hitung durasi & Simpan ke outgoing_cars
            // Sesuai jurnal

            // 1. Cari data masuk terakhir berdasarkan plat nomor
            $entry = IncomingCar::where('car_no', $plate)
                                ->orderBy('id', 'desc')
                                ->first();

            if (!$entry) {
                return response()->json(['status' => 'not_found', 'message' => 'Data masuk tidak ditemukan'], 404);
            }

            // 2. Hitung Durasi
            $entryTime = Carbon::parse($entry->datetime);
            $exitTime = Carbon::now();
            
            // Hitung total detik untuk akurasi, lalu ubah ke format jam
            $totalSeconds = $exitTime->diffInSeconds($entryTime);
            $hours = ceil($totalSeconds / 3600); // Pembulatan ke atas (1 jam 1 menit = 2 jam)
            
            // 3. Hitung Biaya (Misal Rp 2000 per jam)
            $ratePerHour = 2000; 
            $bill = $hours * $ratePerHour;

            // 4. Simpan ke tabel outgoing_cars (Sesuai struktur DB kamu)
            OutgoingCar::create([
                'car_no'     => $plate,
                'entry_time' => $entryTime,
                'exit_time'  => $exitTime,
                'total_time' => $hours . ' Jam', // Atau simpan integer menitnya saja
                'bill'       => $bill
            ]);

            // Kirim perintah buka gerbang KELUAR ke ESP32
            EspCommand::create(['command' => 'OPEN_GATE_EXIT']); 

            return response()->json([
                'status' => 'ok', 
                'bill' => $bill, 
                'duration' => $hours . ' Jam'
            ]);
        }

        return response()->json(['status' => 'bad_request'], 400);
    }
}