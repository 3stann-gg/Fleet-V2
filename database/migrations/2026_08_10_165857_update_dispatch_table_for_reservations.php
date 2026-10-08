<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch', function (Blueprint $table) {

            // Remove old transport request relationship
            $table->dropForeign(['transport_request_id']);
            $table->dropColumn('transport_request_id');

            // Add dispatch number
            $table->string('dispatch_number')
                ->unique()
                ->after('id');

            // Connect dispatch to reservation
            $table->foreignId('reservation_id')
                ->after('dispatch_number')
                ->constrained('reservations')
                ->cascadeOnDelete();

            // Keep vehicle and driver relationships
            // They will be automatically copied from the selected reservation.
        });
    }

    public function down(): void
    {
        Schema::table('dispatch', function (Blueprint $table) {

            // Remove reservation relationship
            $table->dropForeign(['reservation_id']);
            $table->dropColumn('reservation_id');

            // Remove dispatch number
            $table->dropColumn('dispatch_number');

            // Restore transport request relationship
            $table->foreignId('transport_request_id')
                ->after('id')
                ->constrained('transport_requests')
                ->cascadeOnDelete();
        });
    }
};