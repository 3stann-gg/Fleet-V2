<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->decimal('tank_capacity', 8, 2)
                ->nullable()
                ->after('fuel_type');

            $table->decimal('current_fuel', 8, 2)
                ->default(0)
                ->after('tank_capacity');

            $table->decimal('current_odometer', 10, 2)
                ->default(0)
                ->after('current_fuel');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'tank_capacity',
                'current_fuel',
                'current_odometer',
            ]);
        });
    }
};