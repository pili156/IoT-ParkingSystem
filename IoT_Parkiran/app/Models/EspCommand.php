<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class EspCommand extends Model {
    protected $fillable = [
        'device_id', 
        'command', 
        'consumed',
        'execution_result',
        'executed_at'
    ];

    protected $casts = [
        'consumed' => 'boolean', 
        'executed_at' => 'datetime'
    ];

    protected $dates = [
        'executed_at'
    ];
}