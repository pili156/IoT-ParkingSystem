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
                'image_path' => $imgname,
                'status' => 'in'
            ]);

            // Kirim perintah buka gerbang MASUK ke ESP32
            EspCommand::create(['command' => 'OPEN_GATE_ENTER']);

            return response()->json(['status' => 'ok', 'message' => 'Mobil Masuk Tercatat']);

        } elseif ($mode === 'exit') {
            // LOGIKA KELUAR: Hitung durasi & Simpan ke outgoing_cars
            // Sesuai jurnal

            // 1. Cari data masuk terakhir berdasarkan plat nomor
            $entry = IncomingCar::where('car_no', $plate)
                                ->where('status', 'in') // Hanya cari mobil yang masih dalam status 'in'
                                ->orderBy('entry_time', 'desc')
                                ->first();

            if (!$entry) {
                return response()->json(['status' => 'not_found', 'message' => 'Data masuk tidak ditemukan'], 404);
            }

            // 2. Hitung Durasi
            $entryTime = Carbon::parse($entry->datetime);
            $exitTime = Carbon::now();

            // Hitung durasi dalam format: jam:menit:detik
            $duration = $entryTime->diff($exitTime);
            $totalTimeFormatted = $duration->format('%H:%I:%S'); // Format: HH:MM:SS

            // Hitung jumlah jam pembulatan ke atas (untuk tarif)
            $totalSeconds = $entryTime->diffInSeconds($exitTime);
            $totalHours = ceil($totalSeconds / 3600); // Konversi detik ke jam dan bulatkan ke atas

            // 3. Hitung Biaya (Misal Rp 5000 per jam)
            $ratePerHour = 5000;
            $bill = $totalHours * $ratePerHour;

            // 4. Simpan ke tabel outgoing_cars (Sesuai struktur DB kamu)
            $outgoingCar = OutgoingCar::create([
                'car_no'     => $plate,
                'entry_time' => $entryTime,
                'exit_time'  => $exitTime,
                'total_time' => $totalTimeFormatted, // Format HH:MM:SS
                'total_hours' => $totalHours,        // Jam pembulatan
                'bill'       => $bill,
                'image_path' => $imgname             // Tambahkan image_path
            ]);

            // Update status mobil masuk menjadi 'out' agar tidak bisa diproses lagi
            $entry->update(['status' => 'out']);

            // Kirim perintah buka gerbang KELUAR ke ESP32
            EspCommand::create(['command' => 'OPEN_GATE_EXIT']);

            return response()->json([
                'status' => 'ok',
                'bill' => $bill,
                'duration' => $totalTimeFormatted,
                'total_hours' => $totalHours,
                'outgoing_car' => $outgoingCar
            ]);
        }

        return response()->json(['status' => 'bad_request'], 400);
    }
}