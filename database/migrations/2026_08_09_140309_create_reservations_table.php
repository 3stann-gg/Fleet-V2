<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            $table->string('reservation_number')->unique();
            $table->string('patient_name');

            $table->enum('request_type', [
                'Patient Transport',
                'Emergency Transfer',
                'Medical Appointment',
                'Laboratory Transport',
                'Staff Transport',
                'Supply Delivery',
            ]);

            $table->foreignId('vehicle_id')
                ->nullable()
                ->constrained('vehicles')
                ->nullOnDelete();

            $table->foreignId('driver_id')
                ->nullable()
                ->constrained('drivers')
                ->nullOnDelete();

            $table->string('pickup_location');
            $table->string('destination');

            $table->date('schedule_date');
            $table->time('schedule_time');

            $table->enum('priority', [
                'Low',
                'Normal',
                'High',
                'Emergency',
            ])->default('Normal');

            $table->enum('status', [
                'Pending',
                'Approved',
                'Scheduled',
                'Completed',
                'Rejected',
                'Cancelled',
            ])->default('Pending');

            $table->string('contact_number')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};