<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class VehicleEntry extends Model {
    protected $fillable = ['plate','slot_id','entry_time','exit_time','amount','entry_image','exit_image'];
}
