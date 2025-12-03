<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutgoingCar extends Model
{
    protected $table = 'outgoing_cars';

    protected $fillable = [
        'car_no',
        'entry_time',   // Wajib ada untuk rekap
        'exit_time',    // SEBELUMNYA: waktu_keluar
        'total_time',
        'bill'
    ];

    protected $casts = [
        'entry_time' => 'datetime',
        'exit_time'  => 'datetime',
    ];
}