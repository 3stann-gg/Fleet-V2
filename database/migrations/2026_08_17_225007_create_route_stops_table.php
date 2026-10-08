<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_plan_id')
                ->constrained('route_plans')
                ->cascadeOnDelete();
            /*
            |--------------------------------------------------------------------------
            | Stop Order
            |--------------------------------------------------------------------------
            | 1 = first stop
            | 2 = second stop
            | 3 = third stop
            |--------------------------------------------------------------------------
            */
            $table->unsignedInteger('stop_order');
            $table->string('location');
            $table->timestamps();
            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate stop order within one route
            |--------------------------------------------------------------------------
            */
            $table->unique([
                'route_plan_id',
                'stop_order',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stops');
    }
};