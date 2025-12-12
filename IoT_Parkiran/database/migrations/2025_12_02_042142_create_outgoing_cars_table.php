<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('outgoing_cars', function (Blueprint $table) {
            $table->id();
            
            // Plat Nomor
            $table->string('car_no');
            
            // Waktu Masuk & Keluar
            $table->dateTime('entry_time')->nullable(); 
            $table->dateTime('exit_time')->nullable(); 
            
            // Tagihan (Decimal: 10 digit total, 2 di belakang koma. Cukup sampai ratusan juta)
            $table->decimal('bill', 10, 2)->nullable(); 
            
            // Total waktu dalam format teks "02:30:15" (String, bukan Integer)
            $table->string('total_time')->nullable();
            
            // Total durasi pembulatan jam (Integer, misal: 3 jam) -> INI BARU DITAMBAHKAN
            $table->integer('total_hours')->nullable();

            // Path foto mobil saat keluar -> INI BARU DITAMBAHKAN
            $table->string('image_path')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outgoing_cars');
    }
};