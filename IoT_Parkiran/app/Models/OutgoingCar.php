<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutgoingCar extends Model
{
    protected $fillable = [
        'car_no',
        'entry_time',
        'exit_time',
        'total_time',
        'total_hours',
        'bill',
        'image_path'
    ];

    protected $casts = [
        'entry_time' => 'datetime',
        'exit_time' => 'datetime',
        'total_time' => 'integer',
        'total_hours' => 'integer',
        'bill' => 'decimal:2'
    ];

    // Relationship to incoming record
    public function incomingCar()
    {
        return $this->belongsTo(IncomingCar::class, 'car_no', 'car_no')
                    ->whereColumn('datetime', 'entry_time');
    }
}