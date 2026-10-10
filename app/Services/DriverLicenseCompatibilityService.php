<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Vehicle;

class DriverLicenseCompatibilityService
{
    private const CATEGORY_TO_DL_CODE = [
        'L1' => 'A',
        'L2' => 'A',
        'L3' => 'A',
        'L4' => 'A1',
        'L5' => 'A1',
        'L6' => 'A1',
        'L7' => 'A1',
        'M1' => 'B',
        'M2' => 'B1',
        'N1' => 'B2',
        'N2' => 'C',
        'N3' => 'C',
        'M3' => 'D',
        'O1' => 'BE',
        'O2' => 'BE',
        'O3' => 'CE',
        'O4' => 'CE',
    ];

    public function __construct(
        private VehicleOperationalHealthService $operationalHealth,
    ) {}

    public static function requiredDlCode(?string $vehicleCategory): ?string
    {
        return self::CATEGORY_TO_DL_CODE[$vehicleCategory ?? ''] ?? null;
    }

    /** @return array<string, mixed> */
    public function evaluate(?Driver $driver, Vehicle $vehicle): array
    {
        if ($driver === null) {
            return [
                'impact' => 'neutral',
                'label' => 'Not Evaluated - No Driver Selected',
                'message' => 'Driver legal compatibility was not evaluated because no driver is selected.',
                'overall_status' => 'not_evaluated',
                'required_dl_code' => self::requiredDlCode($vehicle->lto_vehicle_category),
                'driver_dl_codes' => [],
                'dl_code_status' => $this->check('not_evaluated', 'Not Evaluated', 'No driver selected.', 'neutral'),
                'transmission_status' => $this->check('not_evaluated', 'Not Evaluated', 'No driver selected.', 'neutral'),
                'license_expiry_status' => $this->check('not_evaluated', 'Not Evaluated', 'No driver selected.', 'neutral'),
                'license_classification_status' => $this->check('not_evaluated', 'Not Evaluated', 'No driver selected.', 'neutral'),
                'special_purpose_status' => $this->check('not_evaluated', 'Not Evaluated', 'No driver selected.', 'neutral'),
                'reasons' => [],
                'warnings' => [],
                'blocking_concerns' => [],
                'information_gaps' => [],
                'essential_gap' => false,
            ];
        }

        $checks = [
            'dl_code_status' => $this->dlCodeCheck($driver, $vehicle),
            'transmission_status' => $this->transmissionCheck($driver, $vehicle),
            'license_expiry_status' => $this->licenseExpiryCheck($driver),
            'license_classification_status' => $this->classificationCheck($driver, $vehicle),
            'special_purpose_status' => $this->specialPurposeCheck($vehicle),
        ];

        $reasons = [];
        $warnings = [];
        $informationGaps = [];
        $blocking = [];

        foreach ($checks as $check) {
            match ($check['impact']) {
                'blocking' => $blocking[] = $check['message'],
                'warning' => $warnings[] = $check['message'],
                'gap' => $informationGaps[] = $check['message'],
                'suitable' => $reasons[] = $check['message'],
                default => null,
            };
        }

        $essentialGap = collect($checks)->contains(
            fn (array $check): bool => ($check['essential_gap'] ?? false) === true
        );

        [$overallStatus, $label, $impact] = match (true) {
            $blocking !== [] => ['not_compatible', 'Not Compatible', 'blocking'],
            $essentialGap => ['insufficient_information', 'Insufficient Information', 'gap'],
            $warnings !== [] => ['compatible_with_advisory', 'Compatible with Advisory', 'warning'],
            default => ['compatible', 'Compatible', 'suitable'],
        };

        return [
            'impact' => $impact,
            'label' => $label,
            'message' => $blocking[0] ?? $informationGaps[0] ?? $warnings[0] ?? 'Driver legal-license requirements are compatible with the vehicle.',
            'overall_status' => $overallStatus,
            'required_dl_code' => self::requiredDlCode($vehicle->lto_vehicle_category),
            'driver_dl_codes' => $driver->dl_codes,
            ...$checks,
            'reasons' => array_values(array_unique($reasons)),
            'warnings' => array_values(array_unique($warnings)),
            'blocking_concerns' => array_values(array_unique($blocking)),
            'information_gaps' => array_values(array_unique($informationGaps)),
            'essential_gap' => $essentialGap,
        ];
    }

    /** @return array<string, mixed> */
    private function dlCodeCheck(Driver $driver, Vehicle $vehicle): array
    {
        $requiredCode = self::requiredDlCode($vehicle->lto_vehicle_category);

        if ($requiredCode === null) {
            return $this->check(
                'not_recorded',
                'Not Determined - Vehicle Category Not Recorded',
                'Required DL Code cannot be determined because the LTO Vehicle Category is not recorded.',
                'gap',
                true,
            );
        }

        $driverCodes = array_values(array_unique($driver->dl_codes));

        if ($driverCodes === []) {
            return $this->check(
                'not_recorded',
                'DL Codes Not Recorded',
                "Driver DL Codes are not recorded; vehicle requires DL Code {$requiredCode}.",
                'gap',
                true,
            );
        }

        if (! in_array($requiredCode, $driverCodes, true)) {
            return $this->check(
                'missing_required_code',
                'Missing Required DL Code',
                "Vehicle requires DL Code {$requiredCode}; driver holds ".implode(', ', $driverCodes).'.',
                'blocking',
            );
        }

        return $this->check(
            'compatible',
            'Compatible',
            "Required DL Code {$requiredCode} is recorded for the driver.",
            'suitable',
        );
    }

    /** @return array<string, mixed> */
    private function transmissionCheck(Driver $driver, Vehicle $vehicle): array
    {
        if ($vehicle->transmission_type === null) {
            return $this->check('not_recorded', 'Not Recorded', 'Vehicle transmission is not recorded.', 'gap', true);
        }

        if ($vehicle->transmission_type === 'semi_automatic') {
            return $this->check(
                'requires_review',
                'Transmission Compatibility Requires Review',
                'Semi-Automatic transmission compatibility requires manual review.',
                'gap',
                true,
            );
        }

        if ($driver->clutch_authorization === null) {
            return $this->check(
                'not_recorded',
                'Clutch Authorization Not Recorded',
                'Driver clutch authorization is not recorded.',
                'gap',
                true,
            );
        }

        if ($vehicle->transmission_type === 'manual' && $driver->clutch_authorization !== 'MT') {
            return $this->check(
                'not_compatible',
                'Not Compatible',
                'Driver is AT-only and may not operate a Manual vehicle.',
                'blocking',
            );
        }

        return $this->check(
            'compatible',
            'Compatible',
            $driver->clutch_authorization === 'MT'
                ? 'MT authorization permits Manual and Automatic vehicles.'
                : 'AT authorization permits this Automatic vehicle.',
            'suitable',
        );
    }

    /** @return array<string, mixed> */
    private function licenseExpiryCheck(Driver $driver): array
    {
        $advisory = $this->operationalHealth->driverLicenseAdvisory($driver);

        return match (true) {
            $advisory['state'] === 'not_recorded' => $this->check(
                'not_recorded',
                'Not Recorded',
                'Driver license expiry is not recorded.',
                'gap',
                true,
            ),
            $advisory['state'] === 'expired' => $this->check(
                'expired',
                'Expired',
                'Driver license has expired.',
                'blocking',
            ),
            str_starts_with($advisory['state'], 'expiring_') => $this->check(
                $advisory['state'],
                $advisory['label'],
                "Driver license is {$advisory['label']}.",
                'warning',
            ),
            default => $this->check('valid', 'Valid', 'Driver license is valid.', 'suitable'),
        };
    }

    /** @return array<string, mixed> */
    private function classificationCheck(Driver $driver, Vehicle $vehicle): array
    {
        if ($vehicle->vehicle_type !== 'Ambulance') {
            return $this->check('not_applicable', 'Not Applicable', 'Professional classification is not required by this vehicle-type rule.', 'suitable');
        }

        $classification = str($driver->license_class ?? '')
            ->lower()
            ->replace(['-', ' '], '_')
            ->toString();

        return match ($classification) {
            'professional' => $this->check('professional', 'Professional', 'Professional license requirement for Ambulance operation is met.', 'suitable'),
            'non_professional' => $this->check('non_professional', 'Non-Professional', 'Ambulance operation requires a Professional driver license classification.', 'blocking'),
            default => $this->check('requires_review', 'Not Recorded / Requires Review', 'Ambulance driver license classification is not reliably recorded.', 'gap', true),
        };
    }

    /** @return array<string, mixed> */
    private function specialPurposeCheck(Vehicle $vehicle): array
    {
        if ($vehicle->vehicle_type !== 'Ambulance') {
            return $this->check('not_applicable', 'Not Applicable', 'Ambulance special-purpose training is not applicable.', 'suitable');
        }

        return $this->check(
            'not_recorded',
            'Not Recorded',
            'Ambulance Special-Purpose Training: Not Recorded.',
            'warning',
        );
    }

    /** @return array{state: string, label: string, message: string, impact: string, essential_gap: bool} */
    private function check(
        string $state,
        string $label,
        string $message,
        string $impact,
        bool $essentialGap = false,
    ): array {
        return compact('state', 'label', 'message', 'impact') + ['essential_gap' => $essentialGap];
    }
}
