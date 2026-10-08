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
        Schema::table('vehicles', function (Blueprint $table) {
            $table->decimal('battery_health_percent', 5, 2)->nullable();
            $table->timestamp('battery_health_updated_at')->nullable();
            $table->date('battery_last_inspection_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'battery_health_percent',
                'battery_health_updated_at',
                'battery_last_inspection_date',
            ]);
        });
    }
};
