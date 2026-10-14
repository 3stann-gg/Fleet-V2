<?php

namespace App\Models;

use App\Services\DriverLicenseCompatibilityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    public const TYPE_OPTIONS = [
        'Ambulance',
        'Patient Van',
        'Service Vehicle',
        'SUV',
        'Motorcycle',
    ];

    public const MOTORCYCLE_POWERTRAIN_TYPES = [
        'gasoline',
        'electric',
    ];

    public const TRANSMISSION_TYPES = [
        'automatic',
        'manual',
        'semi_automatic',
    ];

    public const LTO_VEHICLE_CATEGORIES = [
        'L1',
        'L2',
        'L3',
        'L4',
        'L5',
        'L6',
        'L7',
        'M1',
        'M2',
        'M3',
        'N1',
        'N2',
        'N3',
        'O1',
        'O2',
        'O3',
        'O4',
    ];

    public const LTO_VEHICLE_CATEGORY_LABELS = [
        'L1' => 'Two-wheel moped',
        'L2' => 'Three-wheel moped',
        'L3' => 'Two-wheel motorcycle',
        'L4' => 'Motorcycle with sidecar',
        'L5' => 'Powered tricycle',
        'L6' => 'Light quadricycle',
        'L7' => 'Heavy quadricycle',
        'M1' => 'Passenger vehicle',
        'M2' => 'Passenger vehicle over 8 seats',
        'M3' => 'Heavy passenger vehicle / bus',
        'N1' => 'Light goods vehicle',
        'N2' => 'Medium goods vehicle',
        'N3' => 'Heavy goods vehicle',
        'O1' => 'Light trailer',
        'O2' => 'Trailer',
        'O3' => 'Heavy trailer',
        'O4' => 'Heavy articulated trailer',
    ];

    public const POWERTRAIN_LABELS = [
        'gasoline' => 'Gasoline',
        'diesel' => 'Diesel',
        'hybrid' => 'Hybrid (HEV)',
        'plug_in_hybrid' => 'Plug-in Hybrid (PHEV)',
        'electric' => 'Electric (BEV)',
    ];

    public const READINESS_LABELS = [
        'ready' => 'Ready for Dispatch',
        'in_use' => 'In Use',
        'maintenance' => 'Maintenance',
        'refueling' => 'Refueling',
        'charging' => 'Charging',
        'cleaning' => 'Cleaning / Sanitation',
        'unavailable' => 'Unavailable',
    ];

    public const READINESS_PRECEDENCE = [
        'ready' => 1,
        'in_use' => 2,
        'refueling' => 3,
        'charging' => 4,
        'cleaning' => 5,
        'maintenance' => 6,
        'unavailable' => 7,
    ];

    protected $appends = [
        'powertrain_label',
        'readiness_label',
        'fuel_level_percent',
        'required_dl_code',
    ];

    protected $fillable = [
        'plate_number',
        'vehicle_type',
        'lto_vehicle_category',
        'department',
        'brand',
        'model',
        'purchase_date',
        'insurance_expiry',
        'capacity',
        'fuel_type',
        'powertrain_type',
        'tank_capacity',
        'current_fuel',
        'current_odometer',
        'battery_capacity_kwh',
        'battery_level_percent',
        'estimated_range_km',
        'charging_port_type',
        'battery_health_percent',
        'battery_health_updated_at',
        'battery_last_inspection_date',
        'engine_displacement_cc',
        'transmission_type',
        'helmet_available',
        'cargo_box_available',
        'cargo_capacity_kg',
        'registration_expiry',
        'status',
        'readiness_status',
        'notes',
        'archived_at',
        'archived_by',
        // 'last_service',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'insurance_expiry' => 'date',
        'tank_capacity' => 'decimal:2',
        'current_fuel' => 'decimal:2',
        'current_odometer' => 'decimal:2',
        'battery_capacity_kwh' => 'decimal:2',
        'battery_level_percent' => 'decimal:2',
        'estimated_range_km' => 'decimal:2',
        'battery_health_percent' => 'decimal:2',
        'battery_health_updated_at' => 'datetime',
        'battery_last_inspection_date' => 'date:Y-m-d',
        'engine_displacement_cc' => 'integer',
        'helmet_available' => 'boolean',
        'cargo_box_available' => 'boolean',
        'cargo_capacity_kg' => 'decimal:2',
        'registration_expiry' => 'date:Y-m-d',
        'archived_at' => 'datetime',
    ];

    public function isMotorcycle(): bool
    {
        return $this->vehicle_type === 'Motorcycle';
    }

    public function getRequiredDlCodeAttribute(): ?string
    {
        return DriverLicenseCompatibilityService::requiredDlCode($this->lto_vehicle_category);
    }

    public function getDisplayLabelAttribute(): string
    {
        $brandModel = trim(
            ($this->brand ?? '').
            ' '.
            ($this->model ?? '')
        );
        $vehicleType = trim(
            (string) ($this->vehicle_type ?? '')
        );
        if (
            $brandModel !== '' &&
            $vehicleType !== ''
        ) {
            return $brandModel.
                ' - '.
                $vehicleType;
        }
        if ($brandModel !== '') {
            return $brandModel;
        }
        if ($vehicleType !== '') {
            return $vehicleType;
        }

        return $this->plate_number
            ?: 'Vehicle';
    }

    public function drivers()
    {
        return $this->hasMany(Driver::class, 'assigned_vehicle_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class);
    }

    public function fuelLogs(): HasMany
    {
        return $this->hasMany(FuelLog::class);
    }

    public function chargingLogs(): HasMany
    {
        return $this->hasMany(ChargingLog::class);
    }

    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(
            VehicleCapability::class,
            'vehicle_capability_vehicle',
            'vehicle_id',
            'vehicle_capability_id'
        );
    }

    public function preTripChecklists(): HasMany
    {
        return $this->hasMany(PreTripChecklist::class);
    }

    public function postTripChecklists(): HasMany
    {
        return $this->hasMany(PostTripChecklist::class);
    }

    public function latestPreTripChecklist(): HasOne
    {
        return $this->hasOne(PreTripChecklist::class)->ofMany('checked_at', 'max');
    }

    public function latestPostTripChecklist(): HasOne
    {
        return $this->hasOne(PostTripChecklist::class)->ofMany('submitted_at', 'max');
    }

    public function sanitationLogs(): HasMany
    {
        return $this->hasMany(SanitationLog::class);
    }

    public function trafficViolations(): HasMany
    {
        return $this->hasMany(TrafficViolation::class);
    }

    public function activeSanitationLog(): HasOne
    {
        return $this->hasOne(SanitationLog::class)
            ->whereIn('status', ['pending', 'in_progress'])
            ->ofMany('id', 'max');
    }

    public function getPowertrainLabelAttribute(): string
    {
        return self::POWERTRAIN_LABELS[$this->powertrain_type]
            ?? 'Not specified';
    }

    public function getReadinessLabelAttribute(): string
    {
        if ($this->readiness_status) {
            return self::READINESS_LABELS[$this->readiness_status]
                ?? $this->readiness_status;
        }

        return match ($this->status) {
            'Available' => 'Ready for Dispatch',
            'On Trip' => 'In Use',
            'Maintenance' => 'Maintenance',
            'Out of Service' => 'Unavailable',
            default => 'Not Specified',
        };
    }

    public function getFuelLevelPercentAttribute(): ?float
    {
        $tankCapacity = (float) ($this->tank_capacity ?? 0);

        if ($tankCapacity <= 0 || $this->current_fuel === null) {
            return null;
        }

        return round(min(100, max(0, ((float) $this->current_fuel / $tankCapacity) * 100)), 1);
    }

    public function isOperationallyReady(): bool
    {
        if ($this->readiness_status !== null) {
            return $this->readiness_status === 'ready';
        }

        return $this->status === 'Available';
    }

    public function applyReadinessWithPrecedence(string $readinessStatus): bool
    {
        $currentRank = self::READINESS_PRECEDENCE[$this->readiness_status ?? 'ready'] ?? 0;
        $newRank = self::READINESS_PRECEDENCE[$readinessStatus] ?? 0;

        if ($newRank < $currentRank) {
            return false;
        }

        return $this->update(['readiness_status' => $readinessStatus]);
    }

    public function canReturnToReady(bool $allowInUse = false): bool
    {
        if ($this->status !== 'Available') {
            return false;
        }

        $blockingReadinessStatuses = [
            'unavailable',
            'maintenance',
            'charging',
            'refueling',
        ];

        if (! $allowInUse) {
            $blockingReadinessStatuses[] = 'in_use';
        }

        if (in_array($this->readiness_status, $blockingReadinessStatuses, true)) {
            return false;
        }

        return ! $this->sanitationLogs()
            ->whereIn('status', ['pending', 'in_progress'])
            ->exists();
    }

    /**
     * @return array{state: string, label: string, message: string}
     */
    public function energyAdvisory(?float $plannedDistanceKm): array
    {
        if (! $this->isOperationallyReady()) {
            return [
                'state' => 'not_ready',
                'label' => 'Not Ready',
                'message' => "Vehicle readiness is {$this->readiness_label}.",
            ];
        }

        if ($this->powertrain_type === 'electric') {
            if ($this->estimated_range_km === null || $plannedDistanceKm === null) {
                return [
                    'state' => 'unavailable',
                    'label' => 'Energy Check Unavailable',
                    'message' => 'Estimated range or planned trip distance is unavailable.',
                ];
            }

            if ((float) $this->estimated_range_km < $plannedDistanceKm) {
                return [
                    'state' => 'warning',
                    'label' => 'Warning',
                    'message' => 'Recorded estimated range is below the planned trip distance.',
                ];
            }
        }

        if ($this->powertrain_type === 'plug_in_hybrid') {
            if (
                $this->estimated_range_km !== null
                && $plannedDistanceKm !== null
                && (float) $this->estimated_range_km < $plannedDistanceKm
            ) {
                return [
                    'state' => 'warning',
                    'label' => 'Warning',
                    'message' => 'Electric range is limited; review combined fuel and battery readiness.',
                ];
            }

            if ($this->estimated_range_km === null || $plannedDistanceKm === null) {
                return [
                    'state' => 'unavailable',
                    'label' => 'Energy Check Unavailable',
                    'message' => 'Review the recorded fuel and battery levels before assignment.',
                ];
            }
        }

        return [
            'state' => 'suitable',
            'label' => 'Suitable',
            'message' => 'No readiness or recorded-range warning was identified.',
        ];
    }

    /**
     * @param  iterable<mixed>|null  $requiredCapabilities
     * @return array{state: string, label: string, message: string, missing: array<string>}
     */
    public function capabilityAdvisory($requiredCapabilities): array
    {
        $requiredCapabilities = collect($requiredCapabilities);

        if ($requiredCapabilities->isEmpty()) {
            return [
                'state' => 'neutral',
                'label' => 'No special capability requirements',
                'message' => 'No specific vehicle capabilities are required for this transport request.',
                'missing' => [],
            ];
        }

        $vehicleCapabilitySlugs = $this->relationLoaded('capabilities')
            ? $this->capabilities->pluck('slug')->all()
            : $this->capabilities()->pluck('slug')->all();

        $missingSlugs = [];
        $missingNames = [];

        foreach ($requiredCapabilities as $cap) {
            $slug = is_string($cap) ? $cap : ($cap->slug ?? null);
            $name = is_string($cap) ? $cap : ($cap->name ?? $slug);

            if ($slug && ! in_array($slug, $vehicleCapabilitySlugs, true)) {
                $missingSlugs[] = $slug;
                $missingNames[] = $name;
            }
        }

        if (! empty($missingSlugs)) {
            return [
                'state' => 'warning',
                'label' => 'Capability Mismatch',
                'message' => 'Vehicle lacks required capability: '.implode(', ', $missingNames),
                'missing' => $missingNames,
            ];
        }

        return [
            'state' => 'suitable',
            'label' => 'Suitable Capabilities',
            'message' => 'Vehicle meets all required capabilities for this request.',
            'missing' => [],
        ];
    }

    public function supportsLiquidFuel(): bool
    {
        if ($this->powertrain_type === null) {
            return $this->fuel_type !== 'Electric';
        }

        return in_array(
            $this->powertrain_type,
            ['gasoline', 'diesel', 'hybrid', 'plug_in_hybrid'],
            true
        );
    }

    public function supportsExternalCharging(): bool
    {
        return in_array(
            $this->powertrain_type,
            ['plug_in_hybrid', 'electric'],
            true
        );
    }

    public function lastCompletedMaintenance(): HasOne
    {
        return $this->hasOne(Maintenance::class)
            ->ofMany(
                [
                    'completion_date' => 'max',
                    'id' => 'max',
                ],
                function ($query) {
                    $query->where('status', 'Completed');
                }
            );
    }

    public function locations(): HasMany
    {
        return $this->hasMany(VehicleLocation::class);
    }

    public function latestLocation(): HasOne
    {
        return $this->hasOne(VehicleLocation::class)
            ->ofMany('recorded_at', 'max');
    }

    public function archivedBy()
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}
