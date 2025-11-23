<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ParkingSlot;

class ParkingSlotController extends Controller
{
    public function updateStatus(Request $r)
{
    $slot = ParkingSlot::find($r->slot_id);
    $slot->is_occupied = $r->is_occupied;
    $slot->save();
    
    return ['success'=>true];
}
}
