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
            $table->date('purchase_date')->nullable()->after('year_model');
            $table->date('insurance_expiry')->nullable()->after('purchase_date');
            $table->text('notes')->nullable()->after('insurance_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_date',
                'insurance_expiry',
                'notes',
            ]);
        });
    }
};
