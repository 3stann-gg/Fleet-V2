<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('driver_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->date('charging_date')->index();
            $table->time('charging_time')->nullable();
            $table->decimal('battery_before_percent', 5, 2);
            $table->decimal('battery_after_percent', 5, 2);
            $table->decimal('energy_added_kwh', 10, 2);
            $table->string('charging_location');
            $table->decimal('charging_cost', 10, 2);
            $table->decimal('odometer', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'charging_date']);
            $table->index(['driver_id', 'charging_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_logs');
    }
};
