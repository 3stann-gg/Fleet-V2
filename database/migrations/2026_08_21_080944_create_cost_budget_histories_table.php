<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'cost_budget_histories',
            function (Blueprint $table) {
                $table->id();

                $table->string(
                    'action',
                    30
                );

                $table->decimal(
                    'previous_value',
                    14,
                    2
                )->nullable();

                $table->decimal(
                    'new_value',
                    14,
                    2
                )->nullable();

                $table->string(
                    'period_type',
                    20
                )->default('filter');

                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'cost_budget_histories'
        );
    }
};