<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\ParkingSlot;

class ParkingSlotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ParkingSlot::create(['slot_name' => 'Slot-1', 'status' => 'Empty']);
        ParkingSlot::create(['slot_name' => 'Slot-2', 'status' => 'Full']);
        ParkingSlot::create(['slot_name' => 'Slot-3', 'status' => 'Empty']);
        ParkingSlot::create(['slot_name' => 'Slot-4', 'status' => 'Full']);
    }
}
