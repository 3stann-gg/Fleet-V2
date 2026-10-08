<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {

            $table->string('maintenance_number')
                ->unique()
                ->after('id');

            $table->date('completion_date')
                ->nullable()
                ->after('maintenance_date');

            $table->string('technician')
                ->nullable()
                ->after('completion_date');

            $table->string('priority')
                ->default('Normal')
                ->after('technician');

            $table->unsignedInteger('odometer')
                ->nullable()
                ->after('priority');

            $table->text('parts_used')
                ->nullable()
                ->after('odometer');

            $table->text('notes')
                ->nullable()
                ->after('cost');

            $table->text('description')
                ->change();

            $table->date('next_schedule')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {

            $table->dropColumn([
                'maintenance_number',
                'completion_date',
                'technician',
                'priority',
                'odometer',
                'parts_used',
                'notes',
            ]);
        });
    }
};