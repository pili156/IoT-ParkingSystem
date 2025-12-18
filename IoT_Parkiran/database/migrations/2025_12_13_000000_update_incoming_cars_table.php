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
        Schema::table('incoming_cars', function (Blueprint $table) {
            // Add columns if they don't exist
            if (!Schema::hasColumn('incoming_cars', 'image_path')) {
                $table->string('image_path')->nullable()->after('datetime');
            }
            if (!Schema::hasColumn('incoming_cars', 'status')) {
                $table->string('status')->default('in')->after('image_path');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incoming_cars', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'status']);
        });
    }
};
