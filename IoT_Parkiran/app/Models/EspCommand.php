<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EspCommand extends Model
{
    use HasFactory;

    // Nama tabel (Opsional jika standar, tapi biar yakin)
    protected $table = 'esp_commands';

    // Kolom yang boleh diisi (Mass Assignment)
    protected $fillable = [
        'command',          // Isi perintah: "OPEN_ENTRY", "OPEN_EXIT"
        'is_executed',      // Status: 0 (Belum), 1 (Sudah)
        'device_id',
        'total_time',
        'bill',             // Nominal tagihan (opsional)
        'execution_result'  // Log hasil
    ];

    // Casting tipe data biar otomatis jadi Boolean/Integer
    protected $casts = [
        'is_executed' => 'boolean',
        'bill' => 'decimal:2',
    ];
}