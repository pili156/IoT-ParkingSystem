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
            if (!Schema::hasColumn('incoming_cars', 'slot_name')) {
                $table->string('slot_name')->nullable()->after('image_path');
            }
        });

        Schema::table('outgoing_cars', function (Blueprint $table) {
            if (!Schema::hasColumn('outgoing_cars', 'slot_name')) {
                $table->string('slot_name')->nullable()->after('image_path');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incoming_cars', function (Blueprint $table) {
            if (Schema::hasColumn('incoming_cars', 'slot_name')) {
                $table->dropColumn('slot_name');
            }
        });

        Schema::table('outgoing_cars', function (Blueprint $table) {
            if (Schema::hasColumn('outgoing_cars', 'slot_name')) {
                $table->dropColumn('slot_name');
            }
        });
    }
};
