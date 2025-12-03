<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncomingCar extends Model
{
    protected $table = 'incoming_cars';

    // Sesuaikan dengan kolom di Database kamu
    protected $fillable = [
        'car_no',
        'datetime', // SEBELUMNYA: waktu_masuk (Salah)
    ];

    protected $casts = [
        'datetime' => 'datetime', // Biar enak diolah Carbon
    ];

    // Opsional: Kalau mau otomatis isi datetime saat create
    // Tapi sebaiknya diatur Controller saja biar lebih kontrol
}