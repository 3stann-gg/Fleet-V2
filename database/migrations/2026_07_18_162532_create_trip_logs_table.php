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
        Schema::create('trip_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dispatch_id')
                ->constrained('dispatch')
                ->cascadeOnDelete();

            $table->decimal('start_odometer', 10, 2);
            $table->decimal('end_odometer', 10, 2);

            $table->decimal('distance', 10, 2);
            $table->decimal('fuel_used', 10, 2);

            $table->string('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_logs');
    }
};
