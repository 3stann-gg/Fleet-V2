<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_logs', function (Blueprint $table) {
            $table->string('fuel_number', 50)
                ->unique()
                ->after('id');

            $table->time('refuel_time')
                ->nullable()
                ->after('date');

            $table->string('fuel_type', 50)
                ->after('refuel_time');

            $table->decimal('cost_per_liter', 10, 2)
                ->after('fuel_amount');

            $table->string('fuel_station', 255)
                ->after('odometer');

            $table->string('receipt_number', 40)
                ->nullable()
                ->after('fuel_station');

            $table->string('payment_method', 50)
                ->nullable()
                ->after('receipt_number');

            $table->text('notes')
                ->nullable()
                ->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('fuel_logs', function (Blueprint $table) {
            $table->dropColumn([
                'fuel_number',
                'refuel_time',
                'fuel_type',
                'cost_per_liter',
                'fuel_station',
                'receipt_number',
                'payment_method',
                'notes',
            ]);
        });
    }
};