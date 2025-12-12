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
        Schema::create('esp_commands', function (Blueprint $table) {
            $table->id();
            
            // Perintah untuk ESP32 (OPEN_ENTRY / OPEN_EXIT)
            $table->string('command'); 
            
            // Status apakah perintah sudah diambil ESP32 atau belum
            // 0 = Belum diambil, 1 = Sudah diambil
            $table->boolean('is_executed')->default(false);
            
            // Jika ada tagihan yang perlu ditampilkan di LCD (Nullable)
            $table->decimal('bill', 10, 2)->nullable();
            
            // Menyimpan hasil eksekusi (Opsional, untuk log)
            $table->text('execution_result')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('esp_commands');
    }
};