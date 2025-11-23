<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('parking_slots', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('occupied')->default(false);
            $table->timestamps();
        });

        Schema::create('vehicle_entries', function (Blueprint $table) {
            $table->id();
            $table->string('plate')->index();
            $table->unsignedBigInteger('slot_id')->nullable();
            $table->timestamp('entry_time')->nullable();
            $table->timestamp('exit_time')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('entry_image')->nullable();
            $table->string('exit_image')->nullable();
            $table->timestamps();

            $table->foreign('slot_id')->references('id')->on('parking_slots')->onDelete('set null');
        });

        Schema::create('esp_commands', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->nullable();
            $table->string('command')->nullable();
            $table->boolean('consumed')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('esp_commands');
        Schema::dropIfExists('vehicle_entries');
        Schema::dropIfExists('parking_slots');
    }
};
