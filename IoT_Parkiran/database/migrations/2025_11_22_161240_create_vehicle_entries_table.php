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
        Schema::create('vehicle_entries', function (Blueprint $table) {
            $table->id();
            $table->string('plate');
            $table->timestamp('time_in')->nullable();
            $table->timestamp('time_out')->nullable();
            $table->integer('fee')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_entries');
    }
};
