<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\IncomingCar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage; // Wajib import ini untuk simpan foto

class IncomingCarController extends Controller
{
    /**
     * Menampilkan Halaman Tabel Data Masuk (Web Admin)
     */
    public function index()
    {
        // Ambil data dari yang terbaru
        // Pastikan kolom waktu di View kamu disesuaikan (entry_time atau datetime)
        $incoming = IncomingCar::orderBy('created_at', 'desc')->get();
        return view('incoming-car', compact('incoming'));
    }

    /**
     * API untuk Python Camera (Saat Mobil Masuk)
     * URL: POST /api/incoming-car
     */
    public function store(Request $request)
    {
        // 1. Validasi Input
        $request->validate([
            'car_no' => 'nullable|string',      // Plat nomor (bisa null jika gagal baca)
            'image_base64' => 'nullable|string' // String gambar base64 dari Python
        ]);

        // 2. Proses Simpan Gambar (Jika ada kiriman gambar)
        $imgName = null;
        if ($request->image_base64) {
            // Buat nama file unik: plates/in_TIMESTAMP_PLATNOMOR.jpg
            // str_replace untuk menghapus spasi di plat nomor
            $cleanPlate = str_replace(' ', '', $request->car_no ?? 'unknown');
            $imgName = 'plates/in_' . time() . '_' . $cleanPlate . '.jpg';
            
            // Simpan file ke folder storage/app/public/plates
            Storage::disk('public')->put($imgName, base64_decode($request->image_base64));
        }

        // 3. Simpan Data ke Database
        // Catatan: Pastikan nama kolom 'entry_time' sesuai dengan tabel kamu.
        // Jika di database/model kamu namanya 'datetime', ganti 'entry_time' di bawah menjadi 'datetime'.
        $car = IncomingCar::create([
            'car_no' => $request->car_no ?? 'PENDING',
            'entry_time' => Carbon::now(), 
            'image_path' => $imgName, // Simpan path gambar agar bisa dilihat di web
            'status' => 'in'
        ]);

        // 4. Kirim Respon Sukses (Menggunakan format standar Controller kamu)
        return $this->successResponse($car, 'Data Mobil Masuk Berhasil Disimpan', 201);
    }
}