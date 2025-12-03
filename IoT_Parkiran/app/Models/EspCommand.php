<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class EspCommand extends Model {
    protected $fillable = ['device_id', 'command', 'consumed'];

    protected $casts = [
        'consumed' => 'boolean', // Tambahan biar rapi
    ];
}