<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    public const VEHICLE_TYPE_OPTIONS = Vehicle::TYPE_OPTIONS;

    protected $fillable = [
        //'driver_number',
        'user_id',
        'first_name',
        'last_name',
        'license_number',
        'license_class',
        'license_expiry',
        'contact_number',
        'email',
        'experience',
        'address',
        'emergency_contact',
        'notes',
        'photo',
        'assigned_vehicle_id',
        'status',
        'archived_at',
        'archived_by',
    ];

    protected $casts = [
        'license_expiry' => 'date:Y-m-d',
        'archived_at' => 'datetime',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'assigned_vehicle_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function fuelLogs(): HasMany
    {
        return $this->hasMany(FuelLog::class);
    }

    public function chargingLogs(): HasMany
    {
        return $this->hasMany(ChargingLog::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(VehicleLocation::class);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(DriverVehicleQualification::class);
    }

    public function preTripChecklists(): HasMany
    {
        return $this->hasMany(PreTripChecklist::class);
    }

    public function postTripChecklists(): HasMany
    {
        return $this->hasMany(PostTripChecklist::class);
    }

    public function getLicenseStatusAttribute(): string
    {
        if ($this->license_expiry === null) {
            return 'Not Specified';
        }

        return $this->license_expiry->isPast() ? 'License Expired' : 'License Valid';
    }

    public function qualificationAdvisory(?string $targetVehicleType): array
    {
        $licenseStatus = $this->license_status;
        $licenseWarning = $this->license_expiry !== null && $this->license_expiry->isPast();

        $qualifications = $this->relationLoaded('qualifications')
            ? $this->qualifications->pluck('vehicle_type')->all()
            : $this->qualifications()->pluck('vehicle_type')->all();

        $isQualified = $targetVehicleType !== null
            && in_array($targetVehicleType, $qualifications, true);

        if ($licenseWarning) {
            return [
                'state' => 'warning',
                'label' => 'License Expired',
                'message' => "Driver's license expired on " . $this->license_expiry->format('Y-m-d') . '.',
                'is_qualified' => $isQualified,
                'license_status' => $licenseStatus,
                'authorized_types' => $qualifications,
            ];
        }

        if ($targetVehicleType === null) {
            return [
                'state' => 'neutral',
                'label' => 'Qualification Information Unavailable',
                'message' => 'No assigned vehicle type is available for qualification review.',
                'is_qualified' => null,
                'license_status' => $licenseStatus,
                'authorized_types' => $qualifications,
            ];
        }

        if (empty($qualifications)) {
            return [
                'state' => 'neutral',
                'label' => 'Qualification Information Unavailable',
                'message' => 'No authorized vehicle types have been recorded for this driver.',
                'is_qualified' => null,
                'license_status' => $licenseStatus,
                'authorized_types' => [],
            ];
        }

        if (!$isQualified) {
            return [
                'state' => 'warning',
                'label' => 'Qualification Mismatch',
                'message' => "Driver is not authorized for selected vehicle type ({$targetVehicleType}).",
                'is_qualified' => false,
                'license_status' => $licenseStatus,
                'authorized_types' => $qualifications,
            ];
        }

        return [
            'state' => 'suitable',
            'label' => 'Qualified',
            'message' => $this->license_expiry === null
                ? 'Driver is authorized for this vehicle type; license expiry is not recorded.'
                : 'Driver is authorized for this vehicle type and the license is valid.',
            'is_qualified' => true,
            'license_status' => $licenseStatus,
            'authorized_types' => $qualifications,
        ];
    }

    public function archivedBy()
    {
        return $this->belongsTo(
            User::class,
            'archived_by'
        );
    }
}
