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
        Schema::table('fleet_notifications', function (Blueprint $table) {
            $table->string('active_event_key', 255)
                ->nullable()
                ->after('event_key');

            $table->unique(
                ['user_id', 'active_event_key'],
                'fleet_notifications_user_active_event_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_notifications', function (Blueprint $table) {
            $table->dropUnique(
                'fleet_notifications_user_active_event_unique'
            );

            $table->dropColumn('active_event_key');
        });
    }
};
