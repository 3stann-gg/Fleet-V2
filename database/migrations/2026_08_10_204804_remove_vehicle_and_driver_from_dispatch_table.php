<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->dropForeign(['driver_id']);

            $table->dropColumn([
                'vehicle_id',
                'driver_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('dispatch', function (Blueprint $table) {
            $table->foreignId('vehicle_id')
                ->constrained('vehicles')
                ->cascadeOnDelete();

            $table->foreignId('driver_id')
                ->constrained('drivers')
                ->cascadeOnDelete();
        });
    }
};