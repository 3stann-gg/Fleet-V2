<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedSmallInteger('engine_displacement_cc')->nullable();
            $table->string('transmission_type', 30)->nullable();
            $table->boolean('helmet_available')->nullable();
            $table->boolean('cargo_box_available')->nullable();
            $table->decimal('cargo_capacity_kg', 8, 2)->nullable();
            $table->date('registration_expiry')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'engine_displacement_cc',
                'transmission_type',
                'helmet_available',
                'cargo_box_available',
                'cargo_capacity_kg',
                'registration_expiry',
            ]);
        });
    }
};
