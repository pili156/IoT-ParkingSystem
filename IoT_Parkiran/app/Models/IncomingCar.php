<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncomingCar extends Model
{
    protected $fillable = [
        'car_no',
        'datetime',
        'image_path',
        'status'
    ];

    protected $casts = [
        'datetime' => 'datetime'
    ];

    // Relationship to outgoing records (one car can have multiple entries/exits)
    public function outgoing(): HasMany
    {
        return $this->hasMany(OutgoingCar::class, 'car_no', 'car_no')
                    ->whereColumn('entry_time', 'datetime');
    }
    
    // Relationship to parking slot (if applicable)
    public function slot()
    {
        // This would require a slot_id in the incoming_cars table
        // For now, returning null - you may want to add this relationship later
        return null;
    }
}