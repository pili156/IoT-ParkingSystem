<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OutgoingCar;
use App\Models\IncomingCar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class OutgoingCarController extends Controller
{
    /**
     * Menampilkan Halaman Tabel Data Keluar (Web Admin)
     */
    public function index()
    {
        // Ambil data dari yang terbaru
        $outgoing = OutgoingCar::orderBy('exit_time', 'desc')->get();
        return view('outgoing-car', compact('outgoing'));
    }

    /**
     * API untuk Python Camera (Saat Mobil Keluar)
     * URL: POST /api/outgoing-car
     */
    public function store(Request $request)
    {
        // 1. Validasi Input
        $request->validate([
            'car_no' => 'nullable|string',      // Plat nomor (bisa null jika gagal baca)
            'image_base64' => 'nullable|string' // String gambar base64 dari Python
        ]);

        // 2. Cek apakah mobil masuk sudah ada di database
        $incomingCar = IncomingCar::where('car_no', $request->car_no)
                                  ->where('status', 'in')
                                  ->orderBy('datetime', 'desc')
                                  ->first();

        if (!$incomingCar) {
            return $this->errorResponse('Data mobil masuk tidak ditemukan', [], 404);
        }

        // 3. Proses Simpan Gambar (Jika ada kiriman gambar)
        $imgName = null;
        if ($request->image_base64) {
            // Buat nama file unik: plates/out_TIMESTAMP_PLATNOMOR.jpg
            $cleanPlate = str_replace(' ', '', $request->car_no ?? 'unknown');
            $imgName = 'plates/out_' . time() . '_' . $cleanPlate . '.jpg';

            // Simpan file ke folder storage/app/public/plates
            Storage::disk('public')->put($imgName, base64_decode($request->image_base64));
        }

        // 4. Hitung durasi dan biaya parkir
        $entryTime = $incomingCar->entry_time;  // DateTime masuk
        $exitTime = Carbon::now();              // DateTime keluar sekarang

        // Hitung durasi dalam format: jam:menit:detik
        $duration = $entryTime->diff($exitTime);
        $totalTimeFormatted = $duration->format('%H:%I:%S'); // Format: HH:MM:SS

        // Hitung jumlah jam pembulatan ke atas (untuk tarif)
        $totalSeconds = $entryTime->diffInSeconds($exitTime);
        $totalHours = ceil($totalSeconds / 3600); // Konversi detik ke jam dan bulatkan ke atas

        // Hitung biaya (tarif: Rp 5.000 per jam)
        $tariffPerHour = 5000;
        $bill = $totalHours * $tariffPerHour;

        // 5. Simpan Data ke Tabel Outgoing Cars
        $outgoingCar = OutgoingCar::create([
            'car_no' => $request->car_no ?? $incomingCar->car_no,
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'total_time' => $totalTimeFormatted,
            'total_hours' => $totalHours,
            'bill' => $bill,
            'image_path' => $imgName,
        ]);

        // 6. Update status mobil masuk menjadi 'out' agar tidak bisa diproses lagi
        $incomingCar->update(['status' => 'out']);

        // 7. Kirim Respon Sukses
        return $this->successResponse([
            'outgoing_car' => $outgoingCar,
            'duration_info' => [
                'total_seconds' => $totalSeconds,
                'total_hours_calc' => $totalHours,
                'formatted_duration' => $totalTimeFormatted,
                'bill' => $bill
            ]
        ], 'Data Mobil Keluar Berhasil Disimpan', 201);
    }
}