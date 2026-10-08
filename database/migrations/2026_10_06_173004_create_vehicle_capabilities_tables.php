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
        Schema::create('vehicle_capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_capability_vehicle', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('vehicle_capability_id')->constrained('vehicle_capabilities')->cascadeOnDelete();
            $table->primary(['vehicle_id', 'vehicle_capability_id']);
        });

        Schema::create('reservation_required_capabilities', function (Blueprint $table) {
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('vehicle_capability_id')->constrained('vehicle_capabilities')->cascadeOnDelete();
            $table->primary(['reservation_id', 'vehicle_capability_id'], 'res_req_cap_primary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservation_required_capabilities');
        Schema::dropIfExists('vehicle_capability_vehicle');
        Schema::dropIfExists('vehicle_capabilities');
    }
};

