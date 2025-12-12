<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutgoingCar extends Model
{
    protected $fillable = [
        'car_no',
        'entry_time',
        'exit_time',
        'total_time',   // Sekarang String (Teks)
        'total_hours',  // Integer (Angka bulat)
        'bill',
        'image_path'    // Pastikan ini ada
    ];

    protected $casts = [
        'entry_time' => 'datetime',
        'exit_time' => 'datetime',
        // 'total_time' => 'integer',  <-- HAPUS baris ini, biarkan default string
        'total_hours' => 'integer',
        'bill' => 'decimal:2'
    ];
}