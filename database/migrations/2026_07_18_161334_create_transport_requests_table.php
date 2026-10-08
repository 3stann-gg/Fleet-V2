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
        Schema::create('transport_requests', function (Blueprint $table) {
            $table->id();

            $table->string('request_number')->unique();
            $table->string('department');
            $table->string('requested_by');
            $table->string('patient_name')->nullable();
            $table->string('pickup_location');
            $table->string('destination');
            $table->string('priority');
            $table->string('purpose');
            $table->string('status');
            $table->dateTime('requested_at');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transport_requests');
    }
};
