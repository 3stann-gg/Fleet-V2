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
        Schema::create('sanitation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('dispatch_id')->nullable()->constrained('dispatch')->nullOnDelete();
            $table->enum('reason', ['post_patient_transport', 'routine_end_of_trip', 'contamination_concern', 'other'])->default('routine_end_of_trip');
            $table->enum('cleaning_type', ['routine_cleaning', 'disinfection', 'deep_cleaning'])->default('routine_cleaning');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sanitation_logs');
    }
};
