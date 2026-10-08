<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_plans', function (Blueprint $table) {
            $table->id();
            /*
            |--------------------------------------------------------------------------
            | Route Identification
            |--------------------------------------------------------------------------
            */
            $table->string('route_number', 50)->unique();
            /*
            |--------------------------------------------------------------------------
            | Dispatch Relationship
            |--------------------------------------------------------------------------
            | One dispatch can have one active route plan.
            |--------------------------------------------------------------------------
            */
            $table->foreignId('dispatch_id')
                ->unique()
                ->constrained('dispatch')
                ->cascadeOnDelete();
            /*
            |--------------------------------------------------------------------------
            | Route Information
            |--------------------------------------------------------------------------
            */
            $table->string('origin');
            $table->string('destination');
            /*
            |--------------------------------------------------------------------------
            | Planning Information
            |--------------------------------------------------------------------------
            */
            $table->string('priority', 30)->default('Medium');
            $table->string('department', 100);
            $table->string('status', 50)->default('Draft');
            /*
            |--------------------------------------------------------------------------
            | Departure Schedule
            |--------------------------------------------------------------------------
            */
            $table->date('departure_date');
            $table->time('departure_time');
            /*
            |--------------------------------------------------------------------------
            | Optimization Results
            |--------------------------------------------------------------------------
            */
            $table->decimal('estimated_distance', 10, 2)->nullable();
            // Estimated travel time stored as minutes.
            $table->unsignedInteger('estimated_time')->nullable();
            $table->string('optimization_strategy', 100)->nullable();
            // Expected optimization score: 0–100.
            $table->decimal('optimization_score', 5, 2)->nullable();
            /*
            |--------------------------------------------------------------------------
            | Additional Information
            |--------------------------------------------------------------------------
            */
            $table->string('purpose')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_plans');
    }
};