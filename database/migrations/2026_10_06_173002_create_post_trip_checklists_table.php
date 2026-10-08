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
        Schema::create('post_trip_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_id')->unique()->constrained('dispatch')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('final_odometer', 10, 2)->nullable();
            $table->decimal('fuel_level_remaining', 5, 2)->nullable();
            $table->decimal('battery_level_remaining', 5, 2)->nullable();
            $table->boolean('visible_damage')->default(false);
            $table->string('tire_condition')->nullable();
            $table->string('lights_condition')->nullable();
            $table->string('brake_condition')->nullable();
            $table->boolean('issue_reported')->default(false);
            $table->boolean('maintenance_required')->default(false);
            $table->boolean('cleaning_required')->default(false);
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_trip_checklists');
    }
};
