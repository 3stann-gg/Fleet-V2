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
        Schema::create('traffic_violations', function (Blueprint $table) {
            $table->id();
            $table->string('citation_number')->nullable()->index();
            $table->date('violation_date');
            $table->time('violation_time')->nullable();
            $table->string('location')->nullable();
            $table->string('issuing_agency')->nullable()->index();
            $table->string('violation_type');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dispatch_id')->nullable()->constrained('dispatch')->nullOnDelete();
            $table->foreignId('trip_log_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('responsibility')->default('under_review');
            $table->text('responsibility_notes')->nullable();
            $table->string('status')->default('reported');
            $table->decimal('fine_amount', 12, 2)->nullable();
            $table->date('due_date')->nullable()->index();
            $table->string('payment_status')->default('not_applicable');
            $table->date('payment_date')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('settlement_notes')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['violation_date', 'status']);
            $table->index(['responsibility', 'payment_status']);
            $table->index(['violation_type', 'issuing_agency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('traffic_violations');
    }
};
