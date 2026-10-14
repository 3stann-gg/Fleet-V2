<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    public const DL_CODES = [
        'A',
        'A1',
        'B',
        'B1',
        'B2',
        'C',
        'D',
        'BE',
        'CE',
    ];

    public const DL_CODE_LABELS = [
        'A' => 'Motorcycle (L1, L2, L3)',
        'A1' => 'Tricycle / related L-category (L4-L7)',
        'B' => 'Passenger vehicle (M1)',
        'B1' => 'Passenger van / jeepney (M2)',
        'B2' => 'Light commercial vehicle (N1)',
        'C' => 'Heavy goods vehicle (N2, N3)',
        'D' => 'Heavy passenger vehicle (M3)',
        'BE' => 'Light trailer / articulated vehicle (O1, O2)',
        'CE' => 'Heavy trailer / articulated vehicle (O3, O4)',
    ];

    public const CLUTCH_AUTHORIZATIONS = ['MT', 'AT'];

    public const VEHICLE_TYPE_OPTIONS = Vehicle::TYPE_OPTIONS;

    protected $fillable = [
        // 'driver_number',
        'user_id',
        'first_name',
        'last_name',
        'license_number',
        'license_class',
        'clutch_authorization',
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

    public function trafficViolations(): HasMany
    {
        return $this->hasMany(TrafficViolation::class);
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

    public function licenseCodes(): HasMany
    {
        return $this->hasMany(DriverLicenseCode::class);
    }

    /** @return array<int, string> */
    public function getDlCodesAttribute(): array
    {
        $codes = $this->relationLoaded('licenseCodes')
            ? $this->licenseCodes->pluck('code')
            : $this->licenseCodes()->pluck('code');
        $order = array_flip(self::DL_CODES);

        return $codes->sortBy(
            fn (string $code): int => $order[$code] ?? PHP_INT_MAX
        )->values()->all();
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

        $qualifications = $this->relationLoaded('qualifications')
            ? $this->qualifications->pluck('vehicle_type')->all()
            : $this->qualifications()->pluck('vehicle_type')->all();

        $isQualified = $targetVehicleType !== null
            && in_array($targetVehicleType, $qualifications, true);

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

        if (! $isQualified) {
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
            'message' => 'Driver is internally authorized for this vehicle type.',
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
