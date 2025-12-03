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
            $table->string('car_no');
            // Waktu Masuk: Diperlukan Python untuk menghitung total_time
            $table->dateTime('entry_time')->nullable(); 
            // Waktu Keluar (Menggantikan 'datetime' yang kamu buat)
            $table->dateTime('exit_time')->nullable(); 
            // Tipe data lebih aman untuk mata uang (misal: 8 digit total, 2 digit desimal)
            $table->decimal('bill', 8, 2)->nullable(); 
            // Total waktu yang dihabiskan
            $table->integer('total_time')->nullable();
            
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