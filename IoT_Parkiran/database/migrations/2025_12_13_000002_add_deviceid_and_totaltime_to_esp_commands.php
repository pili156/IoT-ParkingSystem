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
        Schema::table('esp_commands', function (Blueprint $table) {
            if (!Schema::hasColumn('esp_commands', 'device_id')) {
                $table->string('device_id')->nullable()->after('command');
            }
            if (!Schema::hasColumn('esp_commands', 'total_time')) {
                $table->string('total_time')->nullable()->after('bill');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('esp_commands', function (Blueprint $table) {
            if (Schema::hasColumn('esp_commands', 'device_id')) {
                $table->dropColumn('device_id');
            }
            if (Schema::hasColumn('esp_commands', 'total_time')) {
                $table->dropColumn('total_time');
            }
        });
    }
};
