<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('requested_by')
                ->nullable()
                ->after('reservation_number')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('department')
                ->nullable()
                ->after('requested_by')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['requested_by']);
            $table->dropIndex(['department']);

            $table->dropColumn([
                'requested_by',
                'department',
            ]);
        });
    }
};