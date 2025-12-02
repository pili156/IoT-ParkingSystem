<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\IncomingCar;

class OutgoingCar extends Model
{
    protected $table = 'outgoing_cars';

    protected $fillable = [
        'car_no',
        'waktu_keluar',
        'total_time',
        'bill'
    ];

    public $timestamps = true;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($car) {

            // ambil data incoming berdasarkan car_no
            $incoming = IncomingCar::where('car_no', $car->car_no)->first();

            if (!$incoming) {
                throw new \Exception("Car number {$car->car_no} not found in incoming records.");
            }

            // isi waktu keluar otomatis
            $car->waktu_keluar = now();

            // hitung selisih menit
            $minutes = $incoming->waktu_masuk->diffInMinutes($car->waktu_keluar);

            $car->total_time = $minutes;

            // hitung biaya
            $car->bill = $minutes * 2000;
        });
    }
}
