<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutgoingCar extends Model
{
    protected $fillable = ['car_no', 'datetime', 'total_time', 'bill'];
}