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
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('recurrence_frequency', 10)
                ->nullable()
                ->after('next_schedule');
            $table->unsignedTinyInteger('recurrence_interval')
                ->nullable()
                ->after('recurrence_frequency');
            $table->boolean('recurrence_preserve_month_end')
                ->default(false)
                ->after('recurrence_interval');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn([
                'recurrence_frequency',
                'recurrence_interval',
                'recurrence_preserve_month_end',
            ]);
        });
    }
};
