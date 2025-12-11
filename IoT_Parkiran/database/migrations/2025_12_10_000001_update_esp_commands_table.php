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
        // Add the new columns to esp_commands table if they don't exist
        Schema::table('esp_commands', function (Blueprint $table) {
            if (!Schema::hasColumn('esp_commands', 'execution_result')) {
                $table->text('execution_result')->nullable()->after('consumed');
            }
            if (!Schema::hasColumn('esp_commands', 'executed_at')) {
                $table->timestamp('executed_at')->nullable()->after('execution_result');
            }
            if (!Schema::hasColumn('esp_commands', 'device_id')) {
                $table->string('device_id')->nullable()->after('id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('esp_commands', function (Blueprint $table) {
            $table->dropColumn(['execution_result', 'executed_at', 'device_id']);
        });
    }
};