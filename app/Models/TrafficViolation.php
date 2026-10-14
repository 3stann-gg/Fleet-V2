<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficViolation extends Model
{
    use HasFactory;

    public const TYPE_LABELS = [
        'speeding' => 'Speeding', 'illegal_parking' => 'Illegal Parking',
        'traffic_signal_violation' => 'Traffic Signal Violation', 'reckless_driving' => 'Reckless Driving',
        'registration_document_issue' => 'Registration / Document Issue', 'license_document_issue' => 'License / Document Issue',
        'vehicle_equipment_issue' => 'Vehicle Equipment Issue', 'route_restriction' => 'Route Restriction',
        'loading_unloading_violation' => 'Loading / Unloading Violation', 'other' => 'Other',
    ];

    public const RESPONSIBILITY_LABELS = [
        'under_review' => 'Under Review', 'driver' => 'Driver', 'hospital_fleet' => 'Hospital / Fleet',
        'shared' => 'Shared', 'other' => 'Other',
    ];

    public const STATUS_LABELS = [
        'reported' => 'Reported', 'under_review' => 'Under Review', 'contested' => 'Contested',
        'for_settlement' => 'For Settlement', 'settled' => 'Settled', 'dismissed' => 'Dismissed', 'closed' => 'Closed',
    ];

    public const PAYMENT_STATUS_LABELS = [
        'not_applicable' => 'Not Applicable', 'unpaid' => 'Unpaid', 'partially_paid' => 'Partially Paid',
        'paid' => 'Paid', 'waived' => 'Waived',
    ];

    protected $fillable = [
        'citation_number', 'violation_date', 'violation_time', 'location', 'issuing_agency', 'violation_type',
        'description', 'notes', 'driver_id', 'vehicle_id', 'dispatch_id', 'trip_log_id', 'reservation_id',
        'responsibility', 'responsibility_notes', 'status', 'fine_amount', 'due_date', 'payment_status',
        'payment_date', 'payment_reference', 'settlement_notes', 'reported_by', 'reviewed_by', 'reviewed_at',
        'resolved_at', 'archived_at', 'archived_by',
    ];

    protected $attributes = ['responsibility' => 'under_review', 'status' => 'reported', 'payment_status' => 'not_applicable'];

    protected $appends = ['violation_type_label', 'responsibility_label', 'status_label', 'payment_status_label'];

    protected function casts(): array
    {
        return [
            'violation_date' => 'date:Y-m-d', 'fine_amount' => 'decimal:2',
            'due_date' => 'date:Y-m-d', 'payment_date' => 'date:Y-m-d', 'reviewed_at' => 'datetime',
            'resolved_at' => 'datetime', 'archived_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function tripLog(): BelongsTo
    {
        return $this->belongsTo(TripLog::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function getViolationTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->violation_type] ?? $this->violation_type;
    }

    public function getResponsibilityLabelAttribute(): string
    {
        return self::RESPONSIBILITY_LABELS[$this->responsibility] ?? $this->responsibility;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return self::PAYMENT_STATUS_LABELS[$this->payment_status] ?? $this->payment_status;
    }

    public function canTransitionTo(string $status): bool
    {
        if ($status === $this->status) {
            return true;
        }

        return in_array($status, match ($this->status) {
            'reported' => ['under_review', 'contested', 'for_settlement', 'dismissed', 'closed'],
            'under_review' => ['reported', 'contested', 'for_settlement', 'dismissed', 'closed'],
            'contested' => ['under_review', 'for_settlement', 'dismissed', 'closed'],
            'for_settlement' => ['under_review', 'contested', 'settled', 'dismissed', 'closed'],
            'settled' => ['for_settlement', 'closed'], 'dismissed' => ['under_review', 'closed'],
            'closed' => ['under_review'], default => [],
        }, true);
    }
}
