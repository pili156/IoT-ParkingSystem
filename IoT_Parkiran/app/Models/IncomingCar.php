<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncomingCar extends Model
{
    protected $table = 'incoming_cars';

    protected $fillable = [
        'car_no',
        'waktu_masuk',
    ];

    public $timestamps = true;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($car) {
            // waktu masuk otomatis
            $car->waktu_masuk = now();
        });
    }
}
