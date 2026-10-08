<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {

            $table->string('license_class')
                  ->after('license_number');

            $table->foreignId('assigned_vehicle_id')
                  ->nullable()
                  ->after('contact_number')
                  ->constrained('vehicles')
                  ->nullOnDelete();

        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {

            $table->dropForeign(['assigned_vehicle_id']);
            $table->dropColumn('assigned_vehicle_id');
            $table->dropColumn('license_class');

        });
    }
};
