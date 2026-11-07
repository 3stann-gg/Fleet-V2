<?php

namespace App\Http\Controllers;

use App\Models\FleetSetting;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\AuditLogService;
use App\Services\FleetNotificationService;
use App\Services\MaintenanceAuthorityChangedException;
use App\Services\MaintenanceClassificationService;
use App\Services\MySqlDeadlockRetryService;
use App\Services\PreventiveMaintenanceRecurrenceCalculator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidationValidator;

class MaintenanceController extends Controller
{
    use AuthorizesRequests;

    private const RECURRENCE_FIELDS = [
        'recurrence_enabled',
        'recurrence_frequency',
        'recurrence_interval',
        'recurrence_preserve_month_end',
    ];

    public function __construct(
        private MaintenanceClassificationService $maintenanceClassificationService,
        private MySqlDeadlockRetryService $deadlockRetryService,
        private PreventiveMaintenanceRecurrenceCalculator $preventiveMaintenanceRecurrenceCalculator,
    ) {}

    private function calculateCompletedRecurringNextSchedule(
        Maintenance $maintenance,
        string $completionDate,
    ): ?string {
        $frequency = $maintenance->recurrence_frequency;
        $interval = $maintenance->recurrence_interval;
        $preserveMonthEnd = $maintenance->recurrence_preserve_month_end;
        $isManualConfiguration = $frequency === null &&
            $interval === null &&
            ! $preserveMonthEnd;

        if ($isManualConfiguration) {
            return null;
        }

        if ($maintenance->request_type !== 'Normal') {
            throw ValidationException::withMessages([
                'recurrence_frequency' => 'Recurring maintenance is available only for Normal requests.',
            ]);
        }

        if ($maintenance->maintenance_type !== 'Preventive Maintenance') {
            throw ValidationException::withMessages([
                'recurrence_frequency' => 'Recurring maintenance is available only for Preventive Maintenance.',
            ]);
        }

        if (! in_array($frequency, [
            PreventiveMaintenanceRecurrenceCalculator::FREQUENCY_WEEKLY,
            PreventiveMaintenanceRecurrenceCalculator::FREQUENCY_MONTHLY,
        ], true)) {
            throw ValidationException::withMessages([
                'recurrence_frequency' => 'The saved recurrence frequency must be weekly or monthly before maintenance can be completed.',
            ]);
        }

        if ($interval !== 1) {
            throw ValidationException::withMessages([
                'recurrence_interval' => 'The saved recurrence interval must be exactly 1 before maintenance can be completed.',
            ]);
        }

        if (
            $frequency === PreventiveMaintenanceRecurrenceCalculator::FREQUENCY_WEEKLY &&
            $preserveMonthEnd
        ) {
            throw ValidationException::withMessages([
                'recurrence_preserve_month_end' => 'Month-end preservation is available only for monthly recurrence.',
            ]);
        }

        $calculatedNextSchedule = $this->preventiveMaintenanceRecurrenceCalculator
            ->calculateNextDueDate(
                CarbonImmutable::parse(
                    $completionDate,
                    (string) config('app.timezone'),
                ),
                $frequency,
                $interval,
                $preserveMonthEnd,
            )
            ->toDateString();
        $existingNextSchedule = $maintenance->next_schedule?->toDateString();

        if (
            $existingNextSchedule !== null &&
            $existingNextSchedule !== $calculatedNextSchedule
        ) {
            throw ValidationException::withMessages([
                'next_schedule' => 'The saved Next Service Due date conflicts with the date calculated from the completion date.',
            ]);
        }

        return $calculatedNextSchedule;
    }

    private function addNextScheduleChronologyValidation(
        ValidationValidator $validator,
        Request $request,
        ?Maintenance $maintenance = null,
        bool $isLifecycleTransition = false,
    ): void {
        if (
            $isLifecycleTransition ||
            ! $request->exists('next_schedule') ||
            ! $request->filled('next_schedule')
        ) {
            return;
        }

        $requestedNextSchedule = trim((string) $request->input('next_schedule'));
        $storedNextSchedule = $maintenance?->next_schedule?->toDateString();

        if ($maintenance && $requestedNextSchedule === $storedNextSchedule) {
            return;
        }

        $validator->after(function (ValidationValidator $validator) use (
            $request,
            $maintenance,
            $requestedNextSchedule,
        ): void {
            if (
                $validator->errors()->has('next_schedule') ||
                $validator->errors()->has('maintenance_date') ||
                $validator->errors()->has('completion_date')
            ) {
                return;
            }

            $maintenanceDateValue = $request->input('maintenance_date')
                ?? $maintenance?->maintenance_date?->toDateString();
            $completionDateValue = $request->exists('completion_date')
                ? $request->input('completion_date')
                : $maintenance?->completion_date?->toDateString();
            $nextSchedule = Carbon::parse($requestedNextSchedule)->startOfDay();

            if (
                $maintenanceDateValue &&
                $nextSchedule->lessThanOrEqualTo(
                    Carbon::parse($maintenanceDateValue)->startOfDay()
                )
            ) {
                $validator->errors()->add(
                    'next_schedule',
                    'The next service due date must be after the current maintenance date.'
                );

                return;
            }

            if (
                $completionDateValue &&
                $nextSchedule->lessThanOrEqualTo(
                    Carbon::parse($completionDateValue)->startOfDay()
                )
            ) {
                $validator->errors()->add(
                    'next_schedule',
                    'The next service due date must be after the completion date.'
                );
            }
        });
    }

    private function recurrenceConfigurationWasSubmitted(Request $request): bool
    {
        return collect(self::RECURRENCE_FIELDS)
            ->contains(fn (string $field): bool => $request->exists($field));
    }

    private function addRecurrenceConfigurationValidation(
        ValidationValidator $validator,
        Request $request,
        ?Maintenance $maintenance = null,
        bool $isLifecycleTransition = false,
    ): void {
        if ($isLifecycleTransition) {
            return;
        }

        $configurationWasSubmitted =
            $this->recurrenceConfigurationWasSubmitted($request);

        $validator->after(function (ValidationValidator $validator) use (
            $request,
            $maintenance,
            $configurationWasSubmitted,
        ): void {
            if ($configurationWasSubmitted && ! $request->exists('recurrence_enabled')) {
                $validator->errors()->add(
                    'recurrence_enabled',
                    'Specify whether recurring maintenance is enabled.'
                );

                return;
            }

            if (
                $validator->errors()->has('recurrence_enabled') ||
                $validator->errors()->has('recurrence_frequency') ||
                $validator->errors()->has('recurrence_interval') ||
                $validator->errors()->has('recurrence_preserve_month_end') ||
                $validator->errors()->has('next_schedule')
            ) {
                return;
            }

            if (! $configurationWasSubmitted) {
                if (
                    $maintenance?->recurrence_frequency !== null &&
                    $request->exists('maintenance_type') &&
                    $request->input('maintenance_type') !== 'Preventive Maintenance'
                ) {
                    $validator->errors()->add(
                        'maintenance_type',
                        'Disable recurring maintenance before selecting a different service type.'
                    );
                }

            } elseif (! $request->boolean('recurrence_enabled')) {
                if ($request->filled('recurrence_frequency')) {
                    $validator->errors()->add(
                        'recurrence_frequency',
                        'The recurrence frequency must be empty when recurring maintenance is disabled.'
                    );
                }

                if ($request->filled('recurrence_interval')) {
                    $validator->errors()->add(
                        'recurrence_interval',
                        'The recurrence interval must be empty when recurring maintenance is disabled.'
                    );
                }

                if ($request->boolean('recurrence_preserve_month_end')) {
                    $validator->errors()->add(
                        'recurrence_preserve_month_end',
                        'Month-end preservation must be disabled when recurring maintenance is disabled.'
                    );
                }
            } else {
                if (! $request->filled('recurrence_frequency')) {
                    $validator->errors()->add(
                        'recurrence_frequency',
                        'Select a recurrence frequency.'
                    );
                }

                if (! $request->filled('recurrence_interval')) {
                    $validator->errors()->add(
                        'recurrence_interval',
                        'The recurrence interval is required.'
                    );
                }

                if (! $request->exists('recurrence_preserve_month_end')) {
                    $validator->errors()->add(
                        'recurrence_preserve_month_end',
                        'Specify whether month-end preservation is enabled.'
                    );
                }

                if (
                    $request->input('recurrence_frequency') === 'weekly' &&
                    $request->boolean('recurrence_preserve_month_end')
                ) {
                    $validator->errors()->add(
                        'recurrence_preserve_month_end',
                        'Month-end preservation is available only for monthly recurrence.'
                    );
                }
            }

            if (
                $maintenance &&
                in_array($maintenance->status, ['Completed', 'Cancelled'], true) &&
                $configurationWasSubmitted &&
                $this->recurrenceConfigurationDiffersFromRequest($maintenance, $request)
            ) {
                $validator->errors()->add(
                    'recurrence_enabled',
                    'Recurrence configuration is read-only for completed or cancelled maintenance.'
                );
            }

            $effectiveFrequency = $configurationWasSubmitted
                ? ($request->boolean('recurrence_enabled')
                    ? $request->input('recurrence_frequency')
                    : null)
                : $maintenance?->recurrence_frequency;

            if ($effectiveFrequency === null) {
                return;
            }

            $effectiveRequestType = $maintenance
                ? $maintenance->request_type
                : $request->input('request_type');

            if ($effectiveRequestType !== 'Normal') {
                $validator->errors()->add(
                    'recurrence_enabled',
                    'Recurring maintenance is available only for Normal requests.'
                );
            }

            if ($request->input('maintenance_type', $maintenance?->maintenance_type) !== 'Preventive Maintenance') {
                $validator->errors()->add(
                    'maintenance_type',
                    'Recurring maintenance is available only for Preventive Maintenance.'
                );
            }

            if (! $request->exists('next_schedule')) {
                if (
                    $maintenance?->recurrence_frequency === null &&
                    $maintenance?->next_schedule !== null
                ) {
                    $validator->errors()->add(
                        'next_schedule',
                        'Clear the manual Next Service Due date before enabling recurring maintenance.'
                    );
                }

                return;
            }

            $storedNextSchedule = $maintenance?->next_schedule?->toDateString();
            $requestedNextSchedule = $request->filled('next_schedule')
                ? Carbon::parse((string) $request->input('next_schedule'))->toDateString()
                : null;
            $nextScheduleChanged = $requestedNextSchedule !== $storedNextSchedule;

            if ($nextScheduleChanged && $requestedNextSchedule !== null) {
                $validator->errors()->add(
                    'next_schedule',
                    'Recurring maintenance cannot use a manually changed Next Service Due date.'
                );
            }

            if (
                $nextScheduleChanged &&
                $requestedNextSchedule === null &&
                $maintenance?->recurrence_frequency !== null
            ) {
                $validator->errors()->add(
                    'next_schedule',
                    'The saved Next Service Due date cannot be cleared while recurring maintenance remains enabled.'
                );
            }
        });
    }

    private function normalizeRecurrenceConfiguration(
        array $validated,
        Request $request,
    ): array {
        if (! $this->recurrenceConfigurationWasSubmitted($request)) {
            return $validated;
        }

        $enabled = $request->boolean('recurrence_enabled');
        unset($validated['recurrence_enabled']);

        $validated['recurrence_frequency'] = $enabled
            ? (string) $validated['recurrence_frequency']
            : null;
        $validated['recurrence_interval'] = $enabled
            ? (int) $validated['recurrence_interval']
            : null;
        $validated['recurrence_preserve_month_end'] = $enabled
            ? (bool) $validated['recurrence_preserve_month_end']
            : false;

        return $validated;
    }

    private function recurrenceConfigurationDiffersFromRequest(
        Maintenance $maintenance,
        Request $request,
    ): bool {
        $enabled = $request->boolean('recurrence_enabled');

        return $maintenance->recurrence_frequency !== (
            $enabled ? (string) $request->input('recurrence_frequency') : null
        ) || $maintenance->recurrence_interval !== (
            $enabled ? (int) $request->input('recurrence_interval') : null
        ) || $maintenance->recurrence_preserve_month_end !== (
            $enabled ? $request->boolean('recurrence_preserve_month_end') : false
        );
    }

    private function assertLockedRecurrenceConfigurationIsAllowed(
        Maintenance $maintenance,
        array $validated,
    ): void {
        $configurationWasSubmitted =
            array_key_exists('recurrence_frequency', $validated);

        $configurationDiffers =
            $configurationWasSubmitted && (
                $maintenance->recurrence_frequency !== $validated['recurrence_frequency'] ||
                $maintenance->recurrence_interval !== $validated['recurrence_interval'] ||
                $maintenance->recurrence_preserve_month_end !== $validated['recurrence_preserve_month_end']
            );

        if (in_array($maintenance->status, ['Completed', 'Cancelled'], true)) {
            if ($configurationDiffers) {
                throw ValidationException::withMessages([
                    'recurrence_enabled' => 'Recurrence configuration is read-only for completed or cancelled maintenance.',
                ]);
            }
        }

        $effectiveFrequency = $configurationWasSubmitted
            ? $validated['recurrence_frequency']
            : $maintenance->recurrence_frequency;

        if ($effectiveFrequency === null) {
            return;
        }

        if ($maintenance->request_type !== 'Normal') {
            throw ValidationException::withMessages([
                'recurrence_enabled' => 'Recurring maintenance is available only for Normal requests.',
            ]);
        }

        if (($validated['maintenance_type'] ?? $maintenance->maintenance_type) !== 'Preventive Maintenance') {
            throw ValidationException::withMessages([
                'maintenance_type' => 'Recurring maintenance is available only for Preventive Maintenance.',
            ]);
        }

        if (! array_key_exists('next_schedule', $validated)) {
            if (
                $configurationWasSubmitted &&
                $maintenance->recurrence_frequency === null &&
                $maintenance->next_schedule !== null
            ) {
                throw ValidationException::withMessages([
                    'next_schedule' => 'Clear the manual Next Service Due date before enabling recurring maintenance.',
                ]);
            }

            return;
        }

        $storedNextSchedule = $maintenance->next_schedule?->toDateString();
        $requestedNextSchedule = $validated['next_schedule'] === null ||
            $validated['next_schedule'] === ''
                ? null
                : Carbon::parse((string) $validated['next_schedule'])->toDateString();
        $nextScheduleChanged = $requestedNextSchedule !== $storedNextSchedule;

        if ($nextScheduleChanged && $requestedNextSchedule !== null) {
            throw ValidationException::withMessages([
                'next_schedule' => 'Recurring maintenance cannot use a manually changed Next Service Due date.',
            ]);
        }

        if (
            $nextScheduleChanged &&
            $requestedNextSchedule === null &&
            $maintenance->recurrence_frequency !== null
        ) {
            throw ValidationException::withMessages([
                'next_schedule' => 'The saved Next Service Due date cannot be cleared while recurring maintenance remains enabled.',
            ]);
        }
    }

    private function startVehicleMaintenance(Vehicle $vehicle): void
    {
        $vehicle->update(['status' => 'Maintenance']);
        $vehicle->applyReadinessWithPrecedence('maintenance');
    }

    private function releaseVehicleFromMaintenance(Vehicle $vehicle): void
    {
        if ($vehicle->status !== 'Maintenance') {
            return;
        }

        $readinessStatus = $vehicle->readiness_status;

        if ($readinessStatus === 'maintenance') {
            $hasActiveSanitation = $vehicle->sanitationLogs()
                ->whereIn('status', ['pending', 'in_progress'])
                ->exists();
            $readinessStatus = $hasActiveSanitation ? 'cleaning' : 'ready';
        }

        $vehicle->update([
            'status' => 'Available',
            'readiness_status' => $readinessStatus,
        ]);
    }

    /**
     * Display a listing of maintenance records.
     */
    private function getMaintenanceSettings(): array
    {
        $record = FleetSetting::query()
            ->latest('id')
            ->first();
        $settings = $record?->settings ?? [];
        $maintenanceSettings =
            $settings['maintenance'] ?? [];
        /*
        |--------------------------------------------------------------------------
        | Default service types
        |--------------------------------------------------------------------------
        | Used only when serviceTypes is not yet configured
        |--------------------------------------------------------------------------
        */
        $defaultServiceTypes = [
            [
                'id' => 'preventive_maintenance',
                'name' => 'Preventive Maintenance',
                'defaultCost' => 0,
                'active' => true,
            ],
            [
                'id' => 'corrective_repair',
                'name' => 'Corrective Repair',
                'defaultCost' => 0,
                'active' => true,
            ],
            [
                'id' => 'inspection',
                'name' => 'Inspection',
                'defaultCost' => 0,
                'active' => true,
            ],
            [
                'id' => 'oil_change',
                'name' => 'Oil Change',
                'defaultCost' => 0,
                'active' => true,
            ],
            [
                'id' => 'tire_service',
                'name' => 'Tire Service',
                'defaultCost' => 0,
                'active' => true,
            ],
            [
                'id' => 'brake_service',
                'name' => 'Brake Service',
                'defaultCost' => 0,
                'active' => true,
            ],
            [
                'id' => 'engine_service',
                'name' => 'Engine Service',
                'defaultCost' => 0,
                'active' => true,
            ],
            [
                'id' => 'other',
                'name' => 'Other',
                'defaultCost' => 0,
                'active' => true,
            ],
        ];
        /*
        |--------------------------------------------------------------------------
        | Service Types
        |--------------------------------------------------------------------------
        */
        $serviceTypes =
            is_array($maintenanceSettings['serviceTypes'] ?? null)
                ? $maintenanceSettings['serviceTypes']
                : $defaultServiceTypes;
        $activeServiceTypes = collect($serviceTypes)
            ->filter(function ($service) {
                return is_array($service)
                    && ! empty($service['id'])
                    && ! empty($service['name'])
                    && ($service['active'] ?? true) !== false;
            })
            ->map(function ($service) {
                return [
                    'id' => (string) $service['id'],
                    'name' => trim((string) $service['name']),
                    'defaultCost' => (float) ($service['defaultCost'] ?? 0),
                ];
            })
            ->filter(fn ($service) => $service['name'] !== '')
            ->values()
            ->all();
        /*
        |--------------------------------------------------------------------------
        | Providers
        |--------------------------------------------------------------------------
        */
        $providers =
            is_array($maintenanceSettings['providers'] ?? null)
                ? $maintenanceSettings['providers']
                : [];
        $activeProviders = collect($providers)
            ->filter(function ($provider) {
                return is_array($provider)
                    && ! empty($provider['id'])
                    && ! empty($provider['name'])
                    && ($provider['active'] ?? true) !== false;
            })
            ->map(function ($provider) {
                $name = trim((string) $provider['name']);
                $type =
                    ($provider['type'] ?? 'Technician') === 'Workshop'
                        ? 'Workshop'
                        : 'Technician';
                $contractType =
                    ($provider['contractType'] ?? 'Contractual') === 'In-house'
                        ? 'In-house'
                        : 'Contractual';

                return [
                    'id' => (string) $provider['id'],
                    'name' => $name,
                    'type' => $type,
                    'contractType' => $contractType,
                    'display' => "{$name} — {$type} — {$contractType}",
                ];
            })
            ->filter(fn ($provider) => $provider['name'] !== '')
            ->values()
            ->all();

        return [
            'overdueWarnDays' => max(
                1,
                min(
                    90,
                    (int) (
                        $maintenanceSettings['overdueWarnDays']
                        ?? 3
                    )
                )
            ),
            'requireCost' => $maintenanceSettings['requireCost']
                ?? true,
            'defaultType' => trim(
                (string) (
                    $maintenanceSettings['defaultType']
                    ?? 'Preventive Maintenance'
                )
            ) ?: 'Preventive Maintenance',
            'serviceTypes' => $activeServiceTypes,
            'providers' => $activeProviders,
        ];
    }

    private function getMaintenanceScheduleState(
        ?string $nextSchedule,
        int $warningDays
    ): array {
        if (! $nextSchedule) {
            return [
                'days' => null,
                'overdue' => false,
                'dueSoon' => false,
            ];
        }

        $today = now()->startOfDay();

        $schedule = Carbon::parse(
            $nextSchedule
        )->startOfDay();

        $days = (int) round(
            $today->diffInDays(
                $schedule,
                false
            )
        );

        return [
            'days' => $days,
            'overdue' => $days < 0,
            'dueSoon' => $days >= 0 &&
                $days <= $warningDays,
        ];
    }

    private function createMaintenanceDueNotification(
        Maintenance $maintenance,
        int $warningDays
    ): void {
        if (! $maintenance->next_schedule) {
            return;
        }

        $state =
            $this->getMaintenanceScheduleState(
                Carbon::parse(
                    $maintenance->next_schedule
                )->format('Y-m-d'),
                $warningDays
            );

        if (
            ! $state['overdue'] &&
            ! $state['dueSoon']
        ) {
            return;
        }

        $vehicleLabel = trim(
            ($maintenance->vehicle?->brand ?? '').
            ' '.
            ($maintenance->vehicle?->model ?? '')
        );

        if ($vehicleLabel === '') {
            $vehicleLabel = 'Vehicle';
        }

        if ($state['overdue']) {
            $daysOverdue =
                abs($state['days']);

            $message =
                "{$vehicleLabel} maintenance {$maintenance->maintenance_number} is overdue by {$daysOverdue} day".
                ($daysOverdue === 1 ? '' : 's').
                '.';
        } else {
            $days =
                $state['days'];

            $when =
                $days === 0
                    ? 'today'
                    : "in {$days} day".
                        ($days === 1 ? '' : 's');

            $message =
                "{$vehicleLabel} maintenance {$maintenance->maintenance_number} is due {$when}.";
        }

        $eventKey =
            'maintenance_due:'.
            $maintenance->id.
            ':'.
            Carbon::parse(
                $maintenance->next_schedule
            )->format('Y-m-d');

        FleetNotificationService::createUniqueWhenEnabled(
            'maintenanceDue',
            $state['overdue']
                ? 'Maintenance Overdue'
                : 'Maintenance Due Soon',
            $message,
            $eventKey,
            true,
            route('maintenance')
        );
    }

    /**
     * Generate the next maintenance number.
     *
     * Format:
     * MNT-YYYYMM-0001
     */
    private function generateMaintenanceNumber(): string
    {
        $year =
            now()->format('Y');

        $month =
            now()->format('m');

        $prefix =
            'MNT-'.
            $year.
            '-'.
            $month;

        $latestNumber =
            Maintenance::query()
                ->where(
                    'maintenance_number',
                    'like',
                    $prefix.'%'
                )
                ->orderByDesc(
                    'maintenance_number'
                )
                ->value(
                    'maintenance_number'
                );

        $nextSequence = 1;

        if ($latestNumber) {
            /*
            |--------------------------------------------------------------------------
            | Example:
            |
            | MNT-2026-08015
            |
            | Prefix:
            | MNT-2026-08
            |
            | Sequence:
            | 015
            |--------------------------------------------------------------------------
            */
            $lastSequence =
                (int) substr(
                    $latestNumber,
                    strlen($prefix)
                );

            $nextSequence =
                $lastSequence + 1;
        }

        return
            $prefix.
            str_pad(
                (string) $nextSequence,
                3,
                '0',
                STR_PAD_LEFT
            );
    }

    /**
     * Preview next maintenance number
     * for the Add Maintenance modal.
     */
    public function nextNumber()
    {
        $this->authorize(
            'create',
            Maintenance::class
        );

        return response()->json([
            'maintenance_number' => $this->generateMaintenanceNumber(),
        ]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Maintenance::class);

        $user = $request->user();

        $maintenanceSettings =
            $this->getMaintenanceSettings();

        $warningDays =
            $maintenanceSettings['overdueWarnDays'];

        $showArchived =
            $request->boolean('show_archived');

        $maintenances = Maintenance::with('vehicle')
            ->when(
                $showArchived,
                fn ($query) => $query->whereNotNull('archived_at')
            )
            ->when(
                ! $showArchived,
                fn ($query) => $query->whereNull('archived_at')
            )
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($maintenance) use ($warningDays) {
                $daysUntilNextSchedule = null;
                $isOverdue = false;
                $isDueSoon = false;

                if ($maintenance->next_schedule) {
                    $today = now()->startOfDay();

                    $nextSchedule =
                        Carbon::parse(
                            $maintenance->next_schedule
                        )->startOfDay();

                    $daysUntilNextSchedule =
                        $today->diffInDays(
                            $nextSchedule,
                            false
                        );

                    $isOverdue =
                        $daysUntilNextSchedule < 0;

                    $isDueSoon =
                        ! $isOverdue &&
                        $daysUntilNextSchedule <=
                            $warningDays;
                }

                $maintenance->setAttribute(
                    'days_until_next_schedule',
                    $daysUntilNextSchedule
                );

                $maintenance->setAttribute(
                    'maintenance_overdue',
                    $isOverdue
                );

                $maintenance->setAttribute(
                    'maintenance_due_soon',
                    $isDueSoon
                );

                return $maintenance;
            });

        $this->maintenanceClassificationService->enrich($maintenances);

        if ($request->expectsJson()) {
            return response()->json([
                'maintenances' => $maintenances,

                'settings' => [
                    'overdue_warn_days' => $warningDays,
                ],
            ]);
        }

        $maintenancePermissions = [
            'role' => $user->role,
            'canCreate' => $user->can(
                'create',
                Maintenance::class
            ),
            'canUpdate' => $user->hasPermission('maintenance.update'),
            'canArchive' => $user->hasPermission('maintenance.archive'),
            'canBulkArchive' => $user->hasPermission('maintenance.archive'),
            'canRestore' => $user->hasPermission('maintenance.archive'),
        ];

        return view(
            'maintenance.index',
            compact('maintenancePermissions')
        );
    }

    /**
     * Get vehicles available for maintenance scheduling.
     */
    public function availableVehicles()
    {
        $this->authorize('create', Maintenance::class);

        $vehicles = Vehicle::query()
            ->where('status', 'Available')
            ->whereNull('archived_at')
            ->orderBy('brand')
            ->orderBy('model')
            ->get();

        return response()->json([
            'vehicles' => $vehicles,
        ]);
    }

    /**
     * Store a newly created maintenance record.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Maintenance::class);

        $maintenanceSettings =
        $this->getMaintenanceSettings();
        if (! $request->filled('maintenance_type')) {
            $request->merge([
                'maintenance_type' => $maintenanceSettings['defaultType'],
            ]);
        }
        /*
        |--------------------------------------------------------------------------
        | Emergency Maintenance
        |--------------------------------------------------------------------------
        | Emergency requests start immediately.
        |--------------------------------------------------------------------------
        */
        $initialStatus = $request->input('request_type') === 'Emergency'
            ? 'In Progress'
            : 'Scheduled';

        if ($request->input('request_type') === 'Emergency') {
            $request->merge([
                'maintenance_date' => now()->toDateString(),
                'priority' => 'Emergency',
                'completion_date' => null,
            ]);
        } else {
            $request->merge([
                'request_type' => 'Normal',
            ]);
        }

        $validator = Validator::make(
            $request->all(),
            [
                'request_type' => [
                    'required',
                    Rule::in([
                        'Normal',
                        'Emergency',
                    ]),
                ],
                'vehicle_id' => [
                    'required',
                    'exists:vehicles,id',
                ],
                'maintenance_type' => [
                    'required',
                    'string',
                    'max:100',
                    Rule::in(
                        collect($maintenanceSettings['serviceTypes'])
                            ->pluck('name')
                            ->all()
                    ),
                ],
                'description' => [
                    'required',
                    'string',
                ],
                'maintenance_date' => [
                    'required',
                    'date',
                    'after_or_equal:today',
                ],
                'completion_date' => [
                    'nullable',
                    'date',
                    'after_or_equal:today',
                    'after_or_equal:maintenance_date',
                ],
                'next_schedule' => [
                    'nullable',
                    'date',
                ],
                'recurrence_enabled' => [
                    'sometimes',
                    'boolean',
                ],
                'recurrence_frequency' => [
                    'sometimes',
                    'nullable',
                    Rule::in(['weekly', 'monthly']),
                ],
                'recurrence_interval' => [
                    'sometimes',
                    'nullable',
                    'integer',
                    Rule::in([1]),
                ],
                'recurrence_preserve_month_end' => [
                    'sometimes',
                    'boolean',
                ],
                'technician' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::in(
                        collect($maintenanceSettings['providers'])
                            ->pluck('display')
                            ->all()
                    ),
                ],
                'priority' => [
                    'required',
                    'in:Low,Normal,High,Emergency',
                ],
                'odometer' => [
                    'nullable',
                    'integer',
                    'min:0',
                ],
                'parts_used' => [
                    'nullable',
                    'string',
                ],
                'cost' => [
                    'nullable',
                    'numeric',
                    'min:0',
                ],
                'status' => [
                    'prohibited',
                ],
                'notes' => [
                    'nullable',
                    'string',
                ],
            ]
        );

        $this->addNextScheduleChronologyValidation($validator, $request);
        $this->addRecurrenceConfigurationValidation($validator, $request);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please check the maintenance information.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = DB::transaction(function () use (
                $validator,
                $maintenanceSettings,
                $initialStatus,
                $request,
            ) {
                $validated = $this->normalizeRecurrenceConfiguration(
                    $validator->validated(),
                    $request,
                );
                $validated['status'] = $initialStatus;

                $validated['maintenance_number'] =
                    $this->generateMaintenanceNumber();

                if (
                    $maintenanceSettings['requireCost'] &&
                    ($validated['status'] ?? null) === 'Completed' &&
                    (
                        ! isset($validated['cost']) ||
                        $validated['cost'] === null ||
                        $validated['cost'] === ''
                    )
                ) {
                    throw new \Exception(
                        'Cost is required when maintenance is completed.'
                    );
                }

                $vehicle = Vehicle::lockForUpdate()
                    ->findOrFail($validated['vehicle_id']);

                /*
                |--------------------------------------------------------------------------
                | Vehicle must be Available for a new maintenance record
                |--------------------------------------------------------------------------
                */
                if ($vehicle->archived_at) {
                    throw new \Exception(
                        "Vehicle {$vehicle->brand} {$vehicle->model} is archived and cannot be scheduled for maintenance."
                    );
                }

                if ($vehicle->status !== 'Available') {
                    throw new \Exception(
                        "Vehicle {$vehicle->brand} {$vehicle->model} is currently {$vehicle->status} and cannot be scheduled for maintenance."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Create Maintenance
                |--------------------------------------------------------------------------
                */
                $maintenance = Maintenance::create(
                    $validated
                );

                /*
                |--------------------------------------------------------------------------
                | If created directly as In Progress,
                | set Vehicle to Maintenance.
                |--------------------------------------------------------------------------
                */
                if ($validated['status'] === 'In Progress') {
                    $this->startVehicleMaintenance($vehicle);
                }

                $maintenance->load('vehicle');

                AuditLogService::log(
                    module: 'Maintenance Management',
                    action: 'Created',
                    description: "Created maintenance record {$maintenance->maintenance_number}.",
                    record: $maintenance,
                    newValues: $this->getMaintenanceAuditValues(
                        $maintenance
                    )
                );

                return $maintenance;
            });

            $this->createMaintenanceDueNotification(
                $result,
                $maintenanceSettings['overdueWarnDays']
            );

            $this->maintenanceClassificationService->enrichOne($result);

            return response()->json([
                'success' => true,
                'message' => 'Maintenance record requested successfully.',
                'maintenance' => $result,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Please check the maintenance information.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Display the specified maintenance record.
     */
    public function show(Maintenance $maintenance)
    {
        $this->authorize('view', $maintenance);

        $maintenance->load('vehicle');
        $this->maintenanceClassificationService->enrichOne($maintenance);

        return response()->json([
            'maintenance' => $maintenance,
        ]);
    }

    public function transition(
        Request $request,
        Maintenance $maintenance,
    ) {
        return $this->update($request, $maintenance, true);
    }

    /**
     * Update the specified maintenance record.
     */
    public function update(
        Request $request,
        Maintenance $maintenance,
        bool $isLifecycleTransition = false,
    ) {
        $this->authorize('update', $maintenance);

        if ($maintenance->archived_at) {
            return response()->json([
                'success' => false,
                'message' => 'Archived maintenance records cannot be modified.',
            ], 422);
        }

        if (
            $isLifecycleTransition &&
            array_diff(array_keys($request->all()), ['status']) !== []
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Maintenance transition requests may only contain status.',
            ], 422);
        }

        $maintenanceSettings =
            $this->getMaintenanceSettings();

        $activeServiceTypeNames = collect(
            $maintenanceSettings['serviceTypes']
        )
            ->pluck('name')
            ->values()
            ->all();
        $activeProviderValues = collect(
            $maintenanceSettings['providers']
        )
            ->pluck('display')
            ->values()
            ->all();
        $currentServiceType =
            (string) $maintenance->maintenance_type;
        $currentTechnician =
            (string) $maintenance->technician;
        $currentRequestType =
            $maintenance->request_type ?: 'Normal';

        $validator = Validator::make(
            $request->all(),
            [
                'vehicle_id' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'required',
                    'exists:vehicles,id',
                ],
                'maintenance_type' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'required',
                    'string',
                    'max:100',
                    function (
                        $attribute,
                        $value,
                        $fail
                    ) use (
                        $activeServiceTypeNames,
                        $currentServiceType
                    ) {
                        if (
                            ! in_array(
                                $value,
                                $activeServiceTypeNames,
                                true
                            )
                            &&
                            $value !== $currentServiceType
                        ) {
                            $fail(
                                'The selected service type is no longer active.'
                            );
                        }
                    },
                ],

                'description' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'required',
                    'string',
                ],
                'maintenance_date' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'required',
                    'date',
                    'after_or_equal:today',
                ],
                'completion_date' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'nullable',
                    'date',
                    'after_or_equal:today',
                    'after_or_equal:maintenance_date',
                ],
                'next_schedule' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'nullable',
                    'date',
                ],
                'recurrence_enabled' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'sometimes',
                    'boolean',
                ],
                'recurrence_frequency' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'sometimes',
                    'nullable',
                    Rule::in(['weekly', 'monthly']),
                ],
                'recurrence_interval' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'sometimes',
                    'nullable',
                    'integer',
                    Rule::in([1]),
                ],
                'recurrence_preserve_month_end' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'sometimes',
                    'boolean',
                ],
                'technician' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'required',
                    'string',
                    'max:255',
                    function (
                        $attribute,
                        $value,
                        $fail
                    ) use (
                        $activeProviderValues,
                        $currentTechnician
                    ) {
                        if (
                            ! in_array(
                                $value,
                                $activeProviderValues,
                                true
                            )
                            &&
                            $value !== $currentTechnician
                        ) {
                            $fail(
                                'The selected technician or workshop is no longer active.'
                            );
                        }
                    },
                ],
                'priority' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'required',
                    Rule::in(
                        $currentRequestType === 'Emergency'
                            ? ['Emergency']
                            : ['Low', 'Normal', 'High']
                    ),
                ],
                'odometer' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'nullable',
                    'integer',
                    'min:0',
                ],
                'parts_used' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'nullable',
                    'string',
                ],
                'cost' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'nullable',
                    'numeric',
                    'min:0',
                ],
                'status' => $isLifecycleTransition
                    ? ['required', Rule::in(Maintenance::STATUSES)]
                    : ['sometimes', Rule::in([$maintenance->status])],
                'notes' => [
                    Rule::excludeIf($isLifecycleTransition),
                    'nullable',
                    'string',
                ],
            ]
        );

        $this->addNextScheduleChronologyValidation(
            $validator,
            $request,
            $maintenance,
            $isLifecycleTransition,
        );
        $this->addRecurrenceConfigurationValidation(
            $validator,
            $request,
            $maintenance,
            $isLifecycleTransition,
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please check the maintenance information.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $validatedInput = $this->normalizeRecurrenceConfiguration(
                $validator->validated(),
                $request,
            );

            for ($sourceAttempt = 1; $sourceAttempt <= 3; $sourceAttempt++) {
                try {
                    $result = $this->deadlockRetryService->run(function () use (
                        $validatedInput,
                        $maintenance,
                        $maintenanceSettings,
                        $isLifecycleTransition,
                    ) {
                        $discoveredVehicleId = (int) Maintenance::query()
                            ->whereKey($maintenance->id)
                            ->valueOrFail('vehicle_id');
                        $requestedVehicleId = (int) (
                            $validatedInput['vehicle_id'] ?? $discoveredVehicleId
                        );
                        $lockedVehicles = Vehicle::query()
                            ->whereKey([$discoveredVehicleId, $requestedVehicleId])
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get()
                            ->keyBy(fn (Vehicle $vehicle): int => (int) $vehicle->getKey());
                        $maintenance = Maintenance::query()
                            ->lockForUpdate()
                            ->findOrFail($maintenance->id);

                        if ((int) $maintenance->vehicle_id !== $discoveredVehicleId) {
                            throw new MaintenanceAuthorityChangedException(
                                'The maintenance vehicle changed during lock acquisition.'
                            );
                        }

                        if ($maintenance->archived_at) {
                            throw new \Exception(
                                'Archived maintenance records cannot be modified.'
                            );
                        }

                        $oldAuditValues =
                            $this->getMaintenanceAuditValues(
                                $maintenance
                            );

                        $this->assertLockedRecurrenceConfigurationIsAllowed(
                            $maintenance,
                            $validatedInput,
                        );

                        $previousNextSchedule =
                            $maintenance->next_schedule
                                ? Carbon::parse(
                                    $maintenance->next_schedule
                                )->format('Y-m-d')
                                : null;

                        $previousStatus =
                            $maintenance->status;

                        $validated = $validatedInput;
                        if ($isLifecycleTransition) {
                            $validated = array_merge(
                                $maintenance->only([
                                    'vehicle_id',
                                    'maintenance_type',
                                    'description',
                                    'maintenance_date',
                                    'completion_date',
                                    'next_schedule',
                                    'technician',
                                    'priority',
                                    'odometer',
                                    'parts_used',
                                    'cost',
                                    'notes',
                                ]),
                                $validated,
                            );
                        }
                        $newVehicleId = (int) $validated['vehicle_id'];
                        $currentVehicleId = (int) $maintenance->vehicle_id;

                        /*
                        |--------------------------------------------------------------------------
                        | Lock Vehicles in Canonical ID Order
                        |--------------------------------------------------------------------------
                        */
                        $currentVehicle = $lockedVehicles->get($currentVehicleId);
                        $newVehicle = $lockedVehicles->get($newVehicleId);

                        if (! $currentVehicle instanceof Vehicle || ! $newVehicle instanceof Vehicle) {
                            throw new \Exception(
                                'The selected maintenance vehicle could not be found.'
                            );
                        }

                        $currentStatus = $maintenance->status;
                        $newStatus = $isLifecycleTransition
                            ? $validated['status']
                            : $currentStatus;

                        if (! $isLifecycleTransition) {
                            unset($validated['status']);
                        }

                        $maintenance->fill($validated);

                        if (
                            $maintenanceSettings['requireCost'] &&
                            $newStatus === 'Completed' &&
                            (
                                ! isset($validated['cost']) ||
                                $validated['cost'] === null ||
                                $validated['cost'] === ''
                            )
                        ) {
                            throw new \Exception(
                                'Cost is required before maintenance can be completed.'
                            );
                        }
                        /*
                        |--------------------------------------------------------------------------
                        | Maintenance Status Lifecycle
                        |--------------------------------------------------------------------------
                        */
                        /*
                        |--------------------------------------------------------------------------
                        | Prevent Invalid Status Transition
                        |--------------------------------------------------------------------------
                        */
                        if (
                            $isLifecycleTransition &&
                            ! in_array(
                                $newStatus,
                                Maintenance::TRANSITIONS[$currentStatus] ?? [],
                                true,
                            )
                        ) {
                            throw new \Exception(
                                "Cannot change maintenance status from {$currentStatus} to {$newStatus}."
                            );
                        }
                        /*
                        |--------------------------------------------------------------------------
                        | Vehicle Change Rules
                        |--------------------------------------------------------------------------
                        |
                        | Only Scheduled maintenance may change vehicle.
                        |
                        */
                        if ($newVehicleId !== $currentVehicleId) {

                            if ($currentStatus !== 'Scheduled') {
                                throw new \Exception(
                                    'The vehicle cannot be changed once maintenance has started or has already been completed or cancelled.'
                                );
                            }

                            if ($newVehicle->archived_at) {
                                throw new \Exception(
                                    "Vehicle {$newVehicle->brand} {$newVehicle->model} is archived and cannot be assigned."
                                );
                            }

                            if ($newVehicle->status !== 'Available') {
                                throw new \Exception(
                                    "Vehicle {$newVehicle->brand} {$newVehicle->model} is currently {$newVehicle->status} and cannot be assigned."
                                );
                            }
                            /*
                            | Current vehicle remains Available because Scheduled
                            | maintenance has not started yet.
                            |
                            */
                            $maintenance->vehicle_id = $newVehicleId;
                        }
                        /*
                        |--------------------------------------------------------------------------
                        | Start Maintenance
                        |--------------------------------------------------------------------------
                        |
                        | Scheduled → In Progress
                        | Vehicle: Available → Maintenance
                        |
                        */
                        if (
                            $currentStatus !== 'In Progress' &&
                            $newStatus === 'In Progress'
                        ) {
                            /*
                            | If vehicle was changed above, use the new vehicle.
                            */
                            $activeVehicle = $newVehicle;

                            if ($activeVehicle->status !== 'Available') {
                                throw new \Exception(
                                    "Vehicle {$activeVehicle->brand} {$activeVehicle->model} is currently {$activeVehicle->status} and cannot start maintenance."
                                );
                            }

                            $this->startVehicleMaintenance($activeVehicle);
                        }
                        /*
                        |--------------------------------------------------------------------------
                        | Complete Maintenance
                        |--------------------------------------------------------------------------
                        |
                        | In Progress → Completed
                        | Vehicle: Maintenance → Available
                        |
                        */
                        if (
                            $currentStatus !== 'Completed' &&
                            $newStatus === 'Completed'
                        ) {
                            /*
                            | The vehicle associated with this maintenance becomes
                            | available again.
                            */
                            $completionVehicle = $newVehicle;

                            if (empty($validated['completion_date'])) {
                                $validated['completion_date'] =
                                    now()->toDateString();
                            }

                            $calculatedNextSchedule =
                                $this->calculateCompletedRecurringNextSchedule(
                                    $maintenance,
                                    (string) $validated['completion_date'],
                                );

                            if ($calculatedNextSchedule !== null) {
                                $validated['next_schedule'] =
                                    $calculatedNextSchedule;
                            }

                            $this->releaseVehicleFromMaintenance($completionVehicle);
                        }
                        /*
                        |--------------------------------------------------------------------------
                        | Cancel Maintenance
                        |--------------------------------------------------------------------------
                        |
                        | If maintenance was In Progress:
                        | Vehicle: Maintenance → Available
                        |
                        */
                        if (
                            $currentStatus !== 'Cancelled' &&
                            $newStatus === 'Cancelled'
                        ) {
                            $this->releaseVehicleFromMaintenance($currentVehicle);
                            /*
                            | If it was never started, its vehicle stays Available.
                            */
                        }
                        /*
                        |--------------------------------------------------------------------------
                        | Update Maintenance
                        |--------------------------------------------------------------------------
                        */
                        $maintenance->fill($validated);
                        $maintenance->save();

                        $maintenance->refresh();

                        $newAuditValues =
                            $this->getMaintenanceAuditValues(
                                $maintenance
                            );

                        $this->logMaintenanceUpdate(
                            $maintenance,
                            $oldAuditValues,
                            $newAuditValues
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | Load Updated Vehicle
                        |--------------------------------------------------------------------------
                        */
                        $maintenance->load('vehicle');

                        return [
                            'maintenance' => $maintenance,
                            'previousNextSchedule' => $previousNextSchedule,
                            'previousStatus' => $previousStatus,
                        ];
                    });

                    break;
                } catch (MaintenanceAuthorityChangedException $exception) {
                    if ($sourceAttempt === 3) {
                        throw $exception;
                    }
                }
            }

            $updatedMaintenance =
                $result['maintenance'];
            $currentNextSchedule =
                $updatedMaintenance->next_schedule
                    ? Carbon::parse(
                        $updatedMaintenance->next_schedule
                    )->format('Y-m-d')
                    : null;
            $scheduleChanged =
                $result['previousNextSchedule'] !==
                $currentNextSchedule;
            $statusChanged =
                $result['previousStatus'] !==
                $updatedMaintenance->status;

            if (
                $currentNextSchedule &&
                (
                    $scheduleChanged ||
                    $statusChanged
                )
            ) {
                $this->createMaintenanceDueNotification(
                    $updatedMaintenance,
                    $maintenanceSettings['overdueWarnDays']
                );
            }

            $this->maintenanceClassificationService->enrichOne($updatedMaintenance);

            return response()->json([
                'success' => true,
                'message' => 'Maintenance record updated successfully.',
                'maintenance' => $updatedMaintenance,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Please check the maintenance information.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Remove the specified maintenance record.
     */
    public function archive(
        Request $request,
        Maintenance $maintenance
    ) {
        $this->authorize(
            'archive',
            $maintenance
        );

        try {
            DB::transaction(function () use (
                $request,
                $maintenance
            ) {
                $maintenance =
                    Maintenance::with('vehicle')
                        ->lockForUpdate()
                        ->findOrFail(
                            $maintenance->id
                        );

                if ($maintenance->archived_at) {
                    throw new \Exception(
                        'Maintenance record is already archived.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | In Progress Protection
                |--------------------------------------------------------------------------
                */
                if (
                    $maintenance->status ===
                    'In Progress'
                ) {
                    throw new \Exception(
                        "Maintenance {$maintenance->maintenance_number} cannot be archived while In Progress."
                    );
                }

                $oldValues =
                    $this->getMaintenanceAuditValues(
                        $maintenance
                    );

                $maintenance->update([
                    'archived_at' => now(),

                    'archived_by' => $request->user()->id,
                ]);

                AuditLogService::log(
                    module: 'Maintenance Management',
                    action: 'Archived',
                    description: "Archived maintenance record {$maintenance->maintenance_number}.",
                    record: $maintenance,
                    oldValues: $oldValues,
                    newValues: [
                        'archived_at' => $maintenance->archived_at
                            ?->toDateTimeString(),

                        'archived_by' => $request->user()->id,
                    ]
                );
            });

            return response()->json([
                'success' => true,
                'message' => 'Maintenance record archived successfully.',
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function restore(
        Request $request,
        Maintenance $maintenance
    ) {
        $this->authorize(
            'restore',
            $maintenance
        );

        try {
            DB::transaction(function () use (
                $maintenance
            ) {
                $maintenance =
                    Maintenance::lockForUpdate()
                        ->findOrFail(
                            $maintenance->id
                        );

                if (! $maintenance->archived_at) {
                    throw new \Exception(
                        'Maintenance record is not archived.'
                    );
                }

                $oldValues = [
                    'archived_at' => $maintenance->archived_at
                        ?->toDateTimeString(),

                    'archived_by' => $maintenance->archived_by,
                ];

                $maintenance->update([
                    'archived_at' => null,
                    'archived_by' => null,
                ]);

                AuditLogService::log(
                    module: 'Maintenance Management',
                    action: 'Restored',
                    description: "Restored maintenance record {$maintenance->maintenance_number}.",
                    record: $maintenance,
                    oldValues: $oldValues,
                    newValues: [
                        'archived_at' => null,
                        'archived_by' => null,
                    ]
                );
            });

            $maintenance->refresh();
            $maintenance->load('vehicle');
            $this->maintenanceClassificationService->enrichOne($maintenance);

            return response()->json([
                'success' => true,
                'message' => 'Maintenance record restored successfully.',
                'maintenance' => $maintenance,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Bulk delete maintenance records.
     */
    public function bulkArchive(
        Request $request
    ) {
        $this->authorize(
            'archiveAny',
            Maintenance::class
        );

        $validator = Validator::make(
            $request->all(),
            [
                'maintenance_ids' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'maintenance_ids.*' => [
                    'integer',
                    'exists:maintenances,id',
                ],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please select valid maintenance records.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $archivedIds = [];
            $skippedInProgressIds = [];

            DB::transaction(function () use (
                $validator,
                &$archivedIds,
                &$skippedInProgressIds,
                $request
            ) {
                $maintenanceIds =
                    $validator->validated()[
                        'maintenance_ids'
                    ];

                $maintenances =
                    Maintenance::query()
                        ->whereIn(
                            'id',
                            $maintenanceIds
                        )
                        ->whereNull('archived_at')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                foreach (
                    $maintenances as $maintenance
                ) {
                    if (
                        $maintenance->status ===
                        'In Progress'
                    ) {
                        $skippedInProgressIds[] =
                            $maintenance->id;

                        continue;
                    }

                    $oldValues =
                        $this->getMaintenanceAuditValues(
                            $maintenance
                        );

                    $maintenance->update([
                        'archived_at' => now(),

                        'archived_by' => $request->user()->id,
                    ]);

                    AuditLogService::log(
                        module: 'Maintenance Management',
                        action: 'Archived',
                        description: "Archived maintenance record {$maintenance->maintenance_number}.",
                        record: $maintenance,
                        oldValues: $oldValues,
                        newValues: [
                            'archived_at' => $maintenance->archived_at
                                ?->toDateTimeString(),

                            'archived_by' => $request->user()->id,
                        ]
                    );

                    $archivedIds[] =
                        $maintenance->id;
                }
            });

            if (empty($archivedIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only maintenance records that are not In Progress can be archived.',
                    'archived_ids' => [],
                    'skipped_in_progress_ids' => $skippedInProgressIds,
                ], 422);
            }

            $message =
                count($archivedIds) === 1
                    ? 'Maintenance record archived successfully.'
                    : count($archivedIds).
                        ' maintenance records archived successfully.';

            if (
                ! empty(
                    $skippedInProgressIds
                )
            ) {
                $message .=
                    ' In-progress maintenance record(s) were skipped.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'archived_ids' => $archivedIds,
                'skipped_in_progress_ids' => $skippedInProgressIds,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to archive maintenance records.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getMaintenanceAuditValues(
        Maintenance $maintenance
    ): array {
        return [
            'maintenance_number' => $maintenance->maintenance_number,

            'vehicle_id' => $maintenance->vehicle_id,

            'request_type' => $maintenance->request_type,

            'maintenance_type' => $maintenance->maintenance_type,

            'maintenance_date' => $maintenance->maintenance_date
                    ? Carbon::parse(
                        $maintenance->maintenance_date
                    )->format('Y-m-d')
                    : null,

            'completion_date' => $maintenance->completion_date
                    ? Carbon::parse(
                        $maintenance->completion_date
                    )->format('Y-m-d')
                    : null,

            'next_schedule' => $maintenance->next_schedule
                    ? Carbon::parse(
                        $maintenance->next_schedule
                    )->format('Y-m-d')
                    : null,

            'recurrence_frequency' => $maintenance->recurrence_frequency,

            'recurrence_interval' => $maintenance->recurrence_interval,

            'recurrence_preserve_month_end' => $maintenance->recurrence_preserve_month_end,

            'technician' => $maintenance->technician,

            'priority' => $maintenance->priority,

            'odometer' => $maintenance->odometer,

            'cost' => $maintenance->cost,

            'status' => $maintenance->status,
        ];
    }

    private function logMaintenanceUpdate(
        Maintenance $maintenance,
        array $oldValues,
        array $newValues
    ): void {
        $changedOldValues = [];
        $changedNewValues = [];

        foreach ($newValues as $field => $newValue) {
            $oldValue =
                $oldValues[$field] ?? null;

            if ((string) $oldValue !== (string) $newValue) {
                $changedOldValues[$field] =
                    $oldValue;

                $changedNewValues[$field] =
                    $newValue;
            }
        }

        if (empty($changedNewValues)) {
            return;
        }

        $oldStatus =
            $oldValues['status'] ?? null;

        $newStatus =
            $newValues['status'] ?? null;

        $action = 'Updated';

        if (
            $oldStatus !== $newStatus &&
            $newStatus
        ) {
            $action = match ($newStatus) {
                'In Progress' => 'Started',

                'Completed' => 'Completed',

                'Cancelled' => 'Cancelled',

                'Scheduled' => 'Scheduled',

                default => 'Updated',
            };
        }

        $description = match ($action) {
            'Started' => "Started maintenance {$maintenance->maintenance_number}.",

            'Completed' => "Completed maintenance {$maintenance->maintenance_number}.",

            'Cancelled' => "Cancelled maintenance {$maintenance->maintenance_number}.",

            'Scheduled' => "Scheduled maintenance {$maintenance->maintenance_number}.",

            default => "Updated maintenance {$maintenance->maintenance_number}.",
        };

        AuditLogService::log(
            module: 'Maintenance Management',
            action: $action,
            description: $description,
            record: $maintenance,
            oldValues: $changedOldValues,
            newValues: $changedNewValues
        );
    }
}
