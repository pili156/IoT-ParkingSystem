<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\VehicleEntry;

class VehicleController extends Controller
{
    public function registerEntry(Request $r){
    VehicleEntry::create([
        'plate' => $r->plate,
        'time_in' => now()
    ]);
    return ['success'=>true];
    }
    public function registerExit(Request $r){
    $v = VehicleEntry::where('plate',$r->plate)
                     ->whereNull('time_out')->first();

    $v->time_out = now();
    $v->fee = $v->time_in->diffInMinutes($v->time_out) * 1000;
    $v->save();

    return ['fee'=>$v->fee];
    }
}
