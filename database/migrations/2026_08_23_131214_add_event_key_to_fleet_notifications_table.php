<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_notifications', function (Blueprint $table) {
            $table->string('event_key')
                ->nullable()
                ->after('status');

            $table->unique([
                'user_id',
                'event_key',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('fleet_notifications', function (Blueprint $table) {
            $table->dropUnique([
                'user_id',
                'event_key',
            ]);

            $table->dropColumn(
                'event_key'
            );
        });
    }
};