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
            $table->timestamp('resolved_at')
                ->nullable()
                ->after('link');

            $table->string('resolution_reason', 100)
                ->nullable()
                ->after('resolved_at');

            $table->index(
                ['user_id', 'resolved_at'],
                'fleet_notifications_user_resolved_index'
            );

            $table->index(
                ['event_key', 'resolved_at'],
                'fleet_notifications_event_resolved_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_notifications', function (Blueprint $table) {
            $table->dropIndex('fleet_notifications_user_resolved_index');
            $table->dropIndex('fleet_notifications_event_resolved_index');

            $table->dropColumn([
                'resolved_at',
                'resolution_reason',
            ]);
        });
    }
};
