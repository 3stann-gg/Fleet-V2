<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('driver_number')
                ->nullable()
                ->after('id');
        });

        /*
        |--------------------------------------------------------------------------
        | Backfill existing drivers
        |--------------------------------------------------------------------------
        */

        $drivers = DB::table('drivers')
            ->orderBy('id')
            ->get(['id']);

        foreach ($drivers as $index => $driver) {
            DB::table('drivers')
                ->where('id', $driver->id)
                ->update([
                    'driver_number' =>
                        'DRV-' .
                        str_pad(
                            (string) ($index + 1),
                            3,
                            '0',
                            STR_PAD_LEFT
                        ),
                ]);
        }

        Schema::table('drivers', function (Blueprint $table) {
            $table->unique('driver_number');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropUnique([
                'driver_number'
            ]);

            $table->dropColumn(
                'driver_number'
            );
        });
    }
};
