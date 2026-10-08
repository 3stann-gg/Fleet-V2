<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('powertrain_type', 30)
                ->nullable()
                ->after('fuel_type')
                ->index();

            $table->decimal('battery_capacity_kwh', 8, 2)
                ->nullable()
                ->after('current_odometer');

            $table->decimal('battery_level_percent', 5, 2)
                ->nullable()
                ->after('battery_capacity_kwh');

            $table->decimal('estimated_range_km', 10, 2)
                ->nullable()
                ->after('battery_level_percent');

            $table->string('charging_port_type', 100)
                ->nullable()
                ->after('estimated_range_km');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['powertrain_type']);
            $table->dropColumn([
                'powertrain_type',
                'battery_capacity_kwh',
                'battery_level_percent',
                'estimated_range_km',
                'charging_port_type',
            ]);
        });
    }
};
