<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_plans', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Remove old Dispatch relationship
            |--------------------------------------------------------------------------
            */
            $table->dropForeign(['dispatch_id']);
            $table->dropUnique(['dispatch_id']);
            $table->dropColumn('dispatch_id');

            /*
            |--------------------------------------------------------------------------
            | Add Reservation relationship
            |--------------------------------------------------------------------------
            |
            | One reservation can have one route plan.
            |
            */
            $table->foreignId('reservation_id')
                ->unique()
                ->after('route_number')
                ->constrained('reservations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('route_plans', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Remove Reservation relationship
            |--------------------------------------------------------------------------
            */
            $table->dropForeign(['reservation_id']);
            $table->dropUnique(['reservation_id']);
            $table->dropColumn('reservation_id');

            /*
            |--------------------------------------------------------------------------
            | Restore Dispatch relationship
            |--------------------------------------------------------------------------
            */
            $table->foreignId('dispatch_id')
                ->unique()
                ->after('route_number')
                ->constrained('dispatch')
                ->cascadeOnDelete();
        });
    }
};