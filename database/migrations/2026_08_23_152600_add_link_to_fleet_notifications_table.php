<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_notifications', function (Blueprint $table) {
            $table->string('link')
                ->nullable()
                ->after('event_key');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_notifications', function (Blueprint $table) {
            $table->dropColumn('link');
        });
    }
};