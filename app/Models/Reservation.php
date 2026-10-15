<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    use HasFactory;

    public const REQUEST_TYPE_LABELS = [
        'emergency_response' => 'Emergency Response',
        'patient_transfer' => 'Patient Transfer',
        'staff_transport' => 'Staff Transport',
        'medical_supply_delivery' => 'Medical Supply Delivery',
        'laboratory_specimen_transport' => 'Laboratory Specimen Transport',
        'equipment_transfer' => 'Equipment Transfer',
        'administrative_trip' => 'Administrative Trip',
    ];

    public const LEGACY_REQUEST_TYPE_LABELS = [
        'Patient Transport' => 'Patient Transport',
        'Emergency Transfer' => 'Emergency Transfer',
        'Medical Appointment' => 'Medical Appointment',
        'Laboratory Transport' => 'Laboratory Transport',
        'Staff Transport' => 'Staff Transport',
        'Supply Delivery' => 'Supply Delivery',
    ];

    public const PRIORITY_LABELS = [
        'emergency' => 'Emergency',
        'urgent' => 'Urgent',
        'routine' => 'Routine',
        'scheduled' => 'Scheduled',
    ];

    public const LEGACY_PRIORITY_LABELS = [
        'Emergency' => 'Emergency',
        'High' => 'Urgent',
        'Normal' => 'Routine',
        'Low' => 'Scheduled',
    ];

    protected $appends = [
        'request_type_label',
        'priority_label',
    ];

    protected $fillable = [
        'reservation_number',
        'requested_by',
        'department',
        'patient_name',
        'request_type',
        'shipment_id',
        'vehicle_id',
        'driver_id',
        'pickup_location',
        'pickup_hospital_id',
        'destination',
        'destination_hospital_id',
        'schedule_date',
        'schedule_time',
        'priority',
        'status',
        'contact_number',
        'notes',
        'archived_at',
        'archived_by',
    ];

    protected $casts = [
        'schedule_date' => 'date:Y-m-d',
        'archived_at' => 'datetime',
    ];

    public static function supportedRequestTypes(): array
    {
        return array_keys(self::REQUEST_TYPE_LABELS + self::LEGACY_REQUEST_TYPE_LABELS);
    }

    public static function supportedPriorities(): array
    {
        return array_keys(self::PRIORITY_LABELS + self::LEGACY_PRIORITY_LABELS);
    }

    public function getRequestTypeLabelAttribute(): string
    {
        if (! $this->request_type) {
            return 'Not Specified';
        }

        return self::REQUEST_TYPE_LABELS[$this->request_type]
            ?? self::LEGACY_REQUEST_TYPE_LABELS[$this->request_type]
            ?? $this->request_type;
    }

    public function getPriorityLabelAttribute(): string
    {
        if (! $this->priority) {
            return 'Not Specified';
        }

        return self::PRIORITY_LABELS[$this->priority]
            ?? self::LEGACY_PRIORITY_LABELS[$this->priority]
            ?? $this->priority;
    }

    public function priorityRank(): int
    {
        return match ($this->priority) {
            'emergency', 'Emergency' => 1,
            'urgent', 'High' => 2,
            'routine', 'Normal' => 3,
            'scheduled', 'Low' => 4,
            default => 5,
        };
    }

    public function isSupplyDelivery(): bool
    {
        return self::isSupplyDeliveryType($this->request_type);
    }

    public static function isSupplyDeliveryType(?string $requestType): bool
    {
        return in_array($requestType, ['medical_supply_delivery', 'Supply Delivery'], true);
    }

    /** @return list<string> */
    public static function emergencyRequestTypes(): array
    {
        return ['emergency_response', 'Emergency Transfer'];
    }

    public static function isEmergencyRequestType(?string $requestType): bool
    {
        return in_array($requestType, self::emergencyRequestTypes(), true);
    }

    public static function requiresPatientName(?string $requestType): bool
    {
        return in_array($requestType, [
            'emergency_response',
            'patient_transfer',
            'laboratory_specimen_transport',
            'Patient Transport',
            'Emergency Transfer',
            'Medical Appointment',
            'Laboratory Transport',
        ], true);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function dispatch()
    {
        return $this->hasOne(Dispatch::class);
    }

    public function routePlan(): HasOne
    {
        return $this->hasOne(RoutePlan::class);
    }

    public function requester()
    {
        return $this->belongsTo(
            User::class,
            'requested_by'
        );
    }

    public function archivedBy()
    {
        return $this->belongsTo(
            User::class,
            'archived_by'
        );
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function pickupHospital(): BelongsTo
    {
        return $this->belongsTo(
            HospitalFacility::class,
            'pickup_hospital_id'
        );
    }

    public function destinationHospital(): BelongsTo
    {
        return $this->belongsTo(
            HospitalFacility::class,
            'destination_hospital_id'
        );
    }

    public function requiredCapabilities(): BelongsToMany
    {
        return $this->belongsToMany(
            VehicleCapability::class,
            'reservation_required_capabilities',
            'reservation_id',
            'vehicle_capability_id'
        );
    }

    public function trafficViolations(): HasMany
    {
        return $this->hasMany(TrafficViolation::class);
    }

    public function derivedCapabilities(): array
    {
        return match ($this->request_type) {
            'patient_transfer', 'emergency_response', 'Patient Transport', 'Emergency Transfer' => ['patient_transport'],
            'staff_transport', 'Staff Transport' => ['staff_transport'],
            'laboratory_specimen_transport', 'Laboratory Transport' => ['laboratory_specimen_transport'],
            'medical_supply_delivery', 'Supply Delivery' => ['medical_supply_transport'],
            'equipment_transfer' => ['equipment_transport'],
            default => [],
        };
    }
}
