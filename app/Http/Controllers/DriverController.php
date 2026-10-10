<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\FleetSetting;
use App\Models\User;
use App\Notifications\AccountCreatedNotification;
use App\Notifications\AccountUpdatedNotification;
use App\Services\AuditLogService;
use App\Services\FleetNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DriverController extends Controller
{
    use AuthorizesRequests;

    private function getDriverSettings(): array
    {
        $record = FleetSetting::query()
            ->latest('id')
            ->first();
        $settings = $record?->settings ?? [];
        $driverSettings =
            $settings['drivers'] ?? [];
        $allowedDefaultStatuses = [
            'Available',
            'On Leave',
            'Inactive',
        ];
        $defaultStatus =
            $driverSettings['defaultStatus']
            ?? 'Available';
        if (
            ! in_array(
                $defaultStatus,
                $allowedDefaultStatuses,
                true
            )
        ) {
            $defaultStatus = 'Available';
        }

        return [
            'requireLicenseExpiry' => $driverSettings['requireLicenseExpiry'] ?? true,
            'warnLicenseDays' => max(
                1,
                min(
                    180,
                    (int) (
                        $driverSettings['warnLicenseDays']
                        ?? 30
                    )
                )
            ),
            'defaultStatus' => $defaultStatus,
        ];
    }

    private function isLicenseExpiringSoon(
        ?string $expiryDate,
        int $warningDays
    ): bool {
        if (! $expiryDate) {
            return false;
        }

        $expiry = Carbon::parse(
            $expiryDate
        )->startOfDay();

        $today = now()->startOfDay();

        $daysUntilExpiry =
            $today->diffInDays(
                $expiry,
                false
            );

        return
            $daysUntilExpiry >= 0 &&
            $daysUntilExpiry <= $warningDays;
    }

    private function createLicenseExpiryNotification(
        Driver $driver,
        int $warningDays
    ): void {
        if (
            ! $this->isLicenseExpiringSoon(
                $driver->license_expiry
                    ? Carbon::parse(
                        $driver->license_expiry
                    )->format('Y-m-d')
                    : null,
                $warningDays
            )
        ) {
            return;
        }

        $expiry = Carbon::parse(
            $driver->license_expiry
        )->startOfDay();

        $daysUntilExpiry =
            now()
                ->startOfDay()
                ->diffInDays(
                    $expiry,
                    false
                );

        $driverName = trim(
            ($driver->first_name ?? '').
            ' '.
            ($driver->last_name ?? '')
        );

        if ($driverName === '') {
            $driverName = 'Driver';
        }

        $dayLabel =
            $daysUntilExpiry === 0
                ? 'today'
                : "in {$daysUntilExpiry} day".
                    ($daysUntilExpiry === 1 ? '' : 's');

        $eventKey =
            'driver_license_expiring:'.
            $driver->id.
            ':'.
            $expiry->format('Y-m-d');

        FleetNotificationService::createUniqueWhenEnabled(
            'licenseExpiring',
            "{$driver->driver_number} · Driver License Expiring",
            "{$driverName}'s license ({$driver->license_number}) expires {$dayLabel}.",
            $eventKey,
            true,
            route('driver')
        );
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Driver::class);

        $user = $request->user();

        $driverPermissions = [
            'role' => $user->role,
            'canCreate' => $user->can(
                'create',
                Driver::class
            ),
            'canUpdate' => $user->hasPermission('drivers.update'),
            'canArchive' => $user->hasPermission('drivers.archive'),
            'canBulkArchive' => $user->hasPermission('drivers.archive'),
            'canRestore' => $user->hasPermission('drivers.archive'),
        ];

        return view(
            'driver.index',
            compact('driverPermissions')
        );
    }

    public function store(Request $request)
    {
        $this->authorize('create', Driver::class);
        $driverSettings =
            $this->getDriverSettings();
        $requireLicenseExpiry =
            (bool)
            $driverSettings[
                'requireLicenseExpiry'
            ];

        if (
            ! $request->filled(
                'status'
            )
        ) {
            $request->merge([
                'status' => $driverSettings[
                        'defaultStatus'
                    ],
            ]);
        }

        $request->merge([
            'license_number' => trim(
                (string)
                $request->input(
                    'license_number',
                    ''
                )
            ),

            'email' => trim(
                (string)
                $request->input(
                    'email',
                    ''
                )
            ),
        ]);

        $validator =
            Validator::make(
                $request->all(),
                [
                    'first_name' => [
                        'required',
                        'string',
                        'max:255',
                    ],

                    'last_name' => [
                        'required',
                        'string',
                        'max:255',
                    ],

                    'license_number' => [
                        'bail',
                        'required',
                        'string',
                        'min:3',
                        'max:100',
                        Rule::unique(
                            'drivers',
                            'license_number'
                        ),
                    ],

                    'license_class' => [
                        'required',
                        Rule::in([
                            'Professional',
                            'Non-Professional',
                        ]),
                    ],

                    'dl_codes' => [
                        'nullable',
                        'array',
                    ],

                    'dl_codes.*' => [
                        'string',
                        'distinct',
                        Rule::in(Driver::DL_CODES),
                    ],

                    'clutch_authorization' => [
                        'nullable',
                        Rule::in(Driver::CLUTCH_AUTHORIZATIONS),
                    ],

                    'license_expiry' => [
                        $requireLicenseExpiry
                            ? 'required'
                            : 'nullable',
                        'date',
                    ],

                    'contact_number' => [
                        'required',
                        'string',
                        'max:20',
                    ],

                    'email' => [
                        'bail',
                        'required',
                        'email',
                        'max:255',
                        Rule::unique(
                            'users',
                            'email'
                        ),
                        Rule::unique(
                            'drivers',
                            'email'
                        ),
                    ],

                    'experience' => [
                        'nullable',
                        'integer',
                        'min:0',
                    ],

                    'address' => [
                        'nullable',
                        'string',
                    ],

                    'emergency_contact' => [
                        'nullable',
                        'string',
                        'max:20',
                    ],

                    'notes' => [
                        'nullable',
                        'string',
                    ],

                    'assigned_vehicle_id' => [
                        'nullable',
                        'exists:vehicles,id',
                    ],

                    'status' => [
                        'required',

                        Rule::in([
                            'Available',
                            'On Leave',
                            'Inactive',
                        ]),
                    ],

                    'photo' => [
                        'nullable',
                        'image',
                        'mimes:jpg,jpeg,png',
                        'max:2048',
                    ],
                    'qualifications' => ['nullable', 'array'],
                    'qualifications.*' => [
                        'string',
                        Rule::in(Driver::VEHICLE_TYPE_OPTIONS),
                    ],
                ]
            );

        if (
            $validator->fails()
        ) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated =
            $validator->validated();

        $qualifications = $validated['qualifications'] ?? [];
        $dlCodes = $validated['dl_codes'] ?? [];
        unset($validated['qualifications'], $validated['dl_codes']);

        /*
        |--------------------------------------------------------------------------
        | Store Photo First
        |--------------------------------------------------------------------------
        */
        $photoPath = null;

        if (
            $request->hasFile(
                'photo'
            )
        ) {
            $photoPath =
                $request
                    ->file('photo')
                    ->store(
                        'drivers',
                        'public'
                    );

            $validated['photo'] =
                $photoPath;
        }

        try {
            $result =
                DB::transaction(
                    function () use (
                        $validated,
                        $qualifications,
                        $dlCodes,
                    ) {
                        /*
                        |--------------------------------------------------------------------------
                        | Create Driver first
                        |--------------------------------------------------------------------------
                        |
                        | We need the DB ID to generate DRV-001.
                        |--------------------------------------------------------------------------
                        */
                        $driver =
                            Driver::create(
                                collect(
                                    $validated
                                )
                                    ->except(
                                        'user_id'
                                    )
                                    ->toArray()
                            );

                        /*
                        |--------------------------------------------------------------------------
                        | Generate Permanent Driver Number
                        |--------------------------------------------------------------------------
                        */
                        $driverNumber =
                            'DRV-'.
                            str_pad(
                                (string)
                                $driver->id,
                                3,
                                '0',
                                STR_PAD_LEFT
                            );

                        $driver->forceFill([
                            'driver_number' => $driverNumber,
                        ])->save();

                        /*
                        |--------------------------------------------------------------------------
                        | Generate Temporary Password
                        |--------------------------------------------------------------------------
                        |
                        | DRV-001 → #drv001
                        |--------------------------------------------------------------------------
                        */
                        $temporaryPassword =
                            '#'.
                            strtolower(
                                str_replace(
                                    '-',
                                    '',
                                    $driverNumber
                                )
                            );

                        /*
                        |--------------------------------------------------------------------------
                        | Create Driver User Account
                        |--------------------------------------------------------------------------
                        */
                        $user = User::query()->forceCreate([
                            'first_name' => $validated['first_name'],

                            'last_name' => $validated['last_name'],

                            'name' => trim(
                                $validated['first_name'].
                                ' '.
                                $validated['last_name']
                            ),

                            'email' => $validated['email'],

                            'mobile_number' => $validated['contact_number'],

                            'password' => Hash::make(
                                $temporaryPassword
                            ),

                            'role' => 'driver',

                            'job_title' => 'Driver',
                        ]);

                        /*
                        |--------------------------------------------------------------------------
                        | Link Driver ↔ User
                        |--------------------------------------------------------------------------
                        */
                        $driver->forceFill([
                            'user_id' => $user->id,
                        ])->save();

                        $driver->refresh();

                        $this->syncQualifications($driver, $qualifications);
                        $this->syncLicenseCodes($driver, $dlCodes);

                        $driver->load([
                            'user',
                            'vehicle',
                            'qualifications',
                            'licenseCodes',
                        ]);

                        AuditLogService::log(
                            module: 'Driver Management',
                            action: 'Created',
                            description: "Created driver {$driver->driver_number}.",
                            record: $driver,
                            newValues: $this->getDriverAuditValues(
                                $driver
                            )
                        );

                        return [
                            'driver' => $driver,

                            'user' => $user,

                            'plainPassword' => $temporaryPassword,
                        ];
                    }
                );

            $driver =
                $result['driver'];

            $driverUser =
                $result['user'];

            $plainPassword =
                $result['plainPassword'];
            /*
            |--------------------------------------------------------------------------
            | Driver Account Created Email
            |--------------------------------------------------------------------------
            |
            | Driver + User transaction has already succeeded.
            | Mail failure must not roll back Driver creation.
            |--------------------------------------------------------------------------
            */
            try {
                $driverUser->notify(
                    new AccountCreatedNotification(
                        $plainPassword
                    )
                );
            } catch (\Throwable $e) {
                report($e);
            }
            /*
            |--------------------------------------------------------------------------
            | License Expiry Notification
            |--------------------------------------------------------------------------
            | Driver/account creation has already succeeded.
            | A notification failure must not make the request appear failed.
            |--------------------------------------------------------------------------
            */
            try {
                $this->createLicenseExpiryNotification(
                    $driver,
                    $driverSettings['warnLicenseDays']
                );
            } catch (\Throwable $e) {
                report($e);
            }

            return response()->json([
                'success' => true,

                'message' => 'Driver and driver account created successfully!',

                'driver' => $driver,
            ], 201);

        } catch (\Throwable $e) {
            /*
            |--------------------------------------------------------------------------
            | Delete uploaded photo if transaction failed
            |--------------------------------------------------------------------------
            */
            if (
                $photoPath &&
                Storage::disk(
                    'public'
                )->exists(
                    $photoPath
                )
            ) {
                Storage::disk(
                    'public'
                )->delete(
                    $photoPath
                );
            }

            return response()->json([
                'success' => false,

                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(Driver $driver)
    {
        $this->authorize('view', $driver);
        $driver->load(['vehicle', 'qualifications', 'licenseCodes']);

        return response()->json(
            $driver
        );
    }

    public function update(Request $request, Driver $driver)
    {
        $this->authorize('update', $driver);

        $oldAuditValues =
            $this->getDriverAuditValues(
                $driver
            );

        $driverSettings =
            $this->getDriverSettings();
        $requireLicenseExpiry =
            (bool) $driverSettings['requireLicenseExpiry'];

        $previousLicenseExpiry =
            $driver->license_expiry
                ? Carbon::parse(
                    $driver->license_expiry
                )->format('Y-m-d')
                : null;
        $wasExpiringSoon =
            $this->isLicenseExpiringSoon(
                $previousLicenseExpiry,
                $driverSettings['warnLicenseDays']
            );

        $previousAccountDetails = [
            'first_name' => $driver->first_name,

            'last_name' => $driver->last_name,

            'email' => $driver->email,

            'contact_number' => $driver->contact_number,
        ];

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'license_number' => ['required', 'string',
                Rule::unique('drivers')->ignore($driver->id),
            ],
            'license_class' => [
                'required',
                Rule::in(array_values(array_unique([
                    'Professional',
                    'Non-Professional',
                    $driver->license_class,
                ]))),
            ],
            'dl_codes_present' => ['sometimes', 'boolean'],
            'dl_codes' => ['nullable', 'array'],
            'dl_codes.*' => [
                'string',
                'distinct',
                Rule::in(Driver::DL_CODES),
            ],
            'clutch_authorization' => [
                'nullable',
                Rule::in(Driver::CLUTCH_AUTHORIZATIONS),
            ],
            'license_expiry' => [
                $requireLicenseExpiry
                    ? 'required'
                    : 'nullable',
                'date',
            ],
            'contact_number' => 'required|string|max:20',
            'email' => [
                'required',
                'email',
                'max:255',

                Rule::unique('drivers', 'email')
                    ->ignore($driver->id),

                Rule::unique('users', 'email')
                    ->ignore($driver->user_id),
            ],
            'experience' => 'nullable|integer|min:0',
            'address' => 'nullable|string',
            'emergency_contact' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'assigned_vehicle_id' => 'nullable|exists:vehicles,id',
            'status' => [
                'required',
                Rule::in(
                    $driver->status === 'On Duty'
                        ? [
                            'On Duty',
                        ]
                        : [
                            'Available',
                            'On Leave',
                            'Inactive',
                        ]
                ),
            ],
            'qualifications' => ['nullable', 'array'],
            'qualifications.*' => [
                'string',
                Rule::in(Driver::VEHICLE_TYPE_OPTIONS),
            ],
        ]);

        $qualifications = $validated['qualifications'] ?? null;
        $dlCodes = array_key_exists('dl_codes_present', $validated)
            ? ($validated['dl_codes'] ?? [])
            : null;
        unset($validated['qualifications'], $validated['dl_codes'], $validated['dl_codes_present']);

        if ($request->hasFile('photo')) {
            $validated['photo'] = $request
                ->file('photo')
                ->store('drivers', 'public');
        }

        DB::transaction(function () use (
            $driver,
            $validated,
            $qualifications,
            $dlCodes,
        ) {
            /*
            |--------------------------------------------------------------------------
            | Update Driver Profile
            |--------------------------------------------------------------------------
            */
            $driver->update(
                $validated
            );

            if ($qualifications !== null) {
                $this->syncQualifications($driver, $qualifications);
            }

            if ($dlCodes !== null) {
                $this->syncLicenseCodes($driver, $dlCodes);
            }

            /*
            |--------------------------------------------------------------------------
            | Sync Linked Driver Account
            |--------------------------------------------------------------------------
            */
            $user =
                $driver->user;

            if (
                $user &&
                $user->role === 'driver'
            ) {
                $userUpdates = [
                    'first_name' => $driver->first_name,

                    'last_name' => $driver->last_name,

                    'name' => trim(
                        $driver->first_name.
                        ' '.
                        $driver->last_name
                    ),

                    'mobile_number' => $driver->contact_number,
                ];

                if (
                    $driver->wasChanged(
                        'email'
                    )
                ) {
                    $userUpdates['email'] =
                        $driver->email;
                }

                $user->update(
                    $userUpdates
                );
            }
        });

        $driver->refresh()->load('qualifications');

        $newAuditValues =
            $this->getDriverAuditValues(
                $driver
            );

        $changedOldValues = [];
        $changedNewValues = [];

        foreach (
            $newAuditValues as $field => $newValue
        ) {
            $oldValue =
                $oldAuditValues[$field]
                ?? null;

            if (
                (string) $oldValue !==
                (string) $newValue
            ) {
                $changedOldValues[$field] =
                    $oldValue;

                $changedNewValues[$field] =
                    $newValue;
            }
        }

        if (! empty($changedNewValues)) {
            $action = 'Updated';

            if (
                array_key_exists(
                    'assigned_vehicle_id',
                    $changedNewValues
                )
            ) {
                if (
                    $changedNewValues[
                        'assigned_vehicle_id'
                    ] === null
                ) {
                    $action =
                        'Vehicle Unassigned';
                } elseif (
                    $changedOldValues[
                        'assigned_vehicle_id'
                    ] === null
                ) {
                    $action =
                        'Vehicle Assigned';
                } else {
                    $action =
                        'Vehicle Reassigned';
                }
            } elseif (
                array_key_exists(
                    'status',
                    $changedNewValues
                )
            ) {
                $action =
                    'Status Changed';
            }

            $description = match ($action) {
                'Vehicle Assigned' => "Assigned a vehicle to driver {$driver->driver_number}.",
                'Vehicle Unassigned' => "Unassigned the vehicle from driver {$driver->driver_number}.",
                'Vehicle Reassigned' => "Reassigned the vehicle for driver {$driver->driver_number}.",
                'Status Changed' => "Changed the status of driver {$driver->driver_number}.",
                default => "Updated driver {$driver->driver_number}.",
            };

            AuditLogService::log(
                module: 'Driver Management',
                action: $action,
                description: $description,
                record: $driver,
                oldValues: $changedOldValues,
                newValues: $changedNewValues
            );
        }

        $accountDetailsChanged =
        $previousAccountDetails['first_name'] !== $driver->first_name ||
        $previousAccountDetails['last_name'] !== $driver->last_name ||
        $previousAccountDetails['email'] !== $driver->email ||
        $previousAccountDetails['contact_number'] !== $driver->contact_number;

        /*
        |--------------------------------------------------------------------------
        | Driver Account Updated Email
        |--------------------------------------------------------------------------
        */
        $driver->loadMissing('user');

        if (
            $accountDetailsChanged &&
            $driver->user &&
            $driver->user->role === 'driver'
        ) {
            try {
                $driver->user->notify(
                    new AccountUpdatedNotification
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $currentLicenseExpiry =
            $driver->license_expiry
                ? Carbon::parse(
                    $driver->license_expiry
                )->format('Y-m-d')
                : null;

        $isExpiringSoon =
            $this->isLicenseExpiringSoon(
                $currentLicenseExpiry,
                $driverSettings['warnLicenseDays']
            );

        /*
        |--------------------------------------------------------------------------
        | License Expiry Notification
        |--------------------------------------------------------------------------
        |
        | Notify only when:
        | - license has entered the warning window, OR
        | - expiry date was changed to another date inside the warning window.
        |
        */
        $expiryChanged =
            $previousLicenseExpiry !==
            $currentLicenseExpiry;

        if (
            $isExpiringSoon &&
            (
                ! $wasExpiringSoon ||
                $expiryChanged
            )
        ) {
            $this->createLicenseExpiryNotification(
                $driver,
                $driverSettings['warnLicenseDays']
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Driver updated successfully!',
        ]);
    }

    public function archive(
        Request $request,
        Driver $driver
    ) {
        $this->authorize(
            'archive',
            $driver
        );

        try {
            DB::transaction(function () use (
                $request,
                $driver
            ) {
                $driver = Driver::query()
                    ->lockForUpdate()
                    ->findOrFail($driver->id);

                if ($driver->archived_at) {
                    throw new \Exception(
                        'Driver is already archived.'
                    );
                }

                if ($driver->status === 'On Duty') {
                    throw new \Exception(
                        'This driver cannot be archived while On Duty.'
                    );
                }

                $oldValues =
                    $this->getDriverAuditValues(
                        $driver
                    );

                $driver->update([
                    'archived_at' => now(),

                    'archived_by' => $request->user()->id,
                ]);

                AuditLogService::log(
                    module: 'Driver Management',
                    action: 'Archived',
                    description: "Archived driver {$driver->driver_number}.",
                    record: $driver,
                    oldValues: $oldValues,
                    newValues: [
                        'archived_at' => $driver->archived_at
                            ?->toDateTimeString(),

                        'archived_by' => $request->user()->id,
                    ]
                );
            });

            return response()->json([
                'success' => true,
                'message' => 'Driver archived successfully.',
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
        Driver $driver
    ) {
        $this->authorize(
            'restore',
            $driver
        );

        try {
            DB::transaction(function () use (
                $driver
            ) {
                $driver = Driver::query()
                    ->lockForUpdate()
                    ->findOrFail($driver->id);

                if (! $driver->archived_at) {
                    throw new \Exception(
                        'Driver is not archived.'
                    );
                }

                $oldValues = [
                    'archived_at' => $driver->archived_at
                        ?->toDateTimeString(),

                    'archived_by' => $driver->archived_by,
                ];

                $driver->update([
                    'archived_at' => null,
                    'archived_by' => null,
                ]);

                AuditLogService::log(
                    module: 'Driver Management',
                    action: 'Restored',
                    description: "Restored driver {$driver->driver_number}.",
                    record: $driver,
                    oldValues: $oldValues,
                    newValues: [
                        'archived_at' => null,
                        'archived_by' => null,
                    ]
                );
            });

            $driver->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Driver restored successfully.',
                'driver' => $driver,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function bulkArchive(Request $request)
    {
        $this->authorize(
            'archiveAny',
            Driver::class
        );

        $request->validate([
            'ids' => [
                'required',
                'array',
                'min:1',
            ],

            'ids.*' => [
                'integer',
                'exists:drivers,id',
            ],
        ]);

        try {
            $archivedIds = [];

            DB::transaction(function () use (
                $request,
                &$archivedIds
            ) {
                $drivers = Driver::query()
                    ->whereIn(
                        'id',
                        $request->ids
                    )
                    ->whereNull('archived_at')
                    ->lockForUpdate()
                    ->get();

                foreach ($drivers as $driver) {

                    if ($driver->status === 'On Duty') {
                        continue;
                    }

                    $oldValues =
                        $this->getDriverAuditValues(
                            $driver
                        );

                    $driver->update([
                        'archived_at' => now(),

                        'archived_by' => $request->user()->id,
                    ]);

                    AuditLogService::log(
                        module: 'Driver Management',
                        action: 'Archived',
                        description: "Archived driver {$driver->driver_number}.",
                        record: $driver,
                        oldValues: $oldValues,
                        newValues: [
                            'archived_at' => $driver->archived_at
                                ?->toDateTimeString(),

                            'archived_by' => $request->user()->id,
                        ]
                    );

                    $archivedIds[] =
                        $driver->id;
                }
            });

            if (empty($archivedIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only drivers who are not On Duty can be archived.',
                    'archived_ids' => [],
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => count($archivedIds) === 1
                        ? 'Driver archived successfully.'
                        : count($archivedIds).
                            ' drivers archived successfully.',
                'archived_ids' => $archivedIds,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to archive drivers.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getDrivers(
        Request $request
    ) {
        $this->authorize('viewAny', Driver::class);

        $user = $request->user();

        $driverSettings =
            $this->getDriverSettings();
        $warningDays =
            $driverSettings['warnLicenseDays'];
        $query = Driver::with([
            'vehicle',
            'user',
            'qualifications',
            'licenseCodes',
        ])
            ->latest();

        if ($request->boolean('show_archived')) {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }
        /*
        |--------------------------------------------------------------------------
        | Driver self-scope
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('driver')) {
            $query->where(
                'user_id',
                $user->id
            );
        }
        $drivers = $query
            ->get()
            ->map(function ($driver) use ($warningDays) {
                $daysUntilExpiry = null;
                $licenseExpired = false;
                $licenseExpiringSoon = false;

                if ($driver->license_expiry) {
                    $expiry =
                        Carbon::parse(
                            $driver->license_expiry
                        )->startOfDay();
                    $today =
                        now()->startOfDay();
                    $daysUntilExpiry =
                        $today->diffInDays(
                            $expiry,
                            false
                        );
                    $licenseExpired =
                        $daysUntilExpiry < 0;
                    $licenseExpiringSoon =
                        ! $licenseExpired &&
                        $daysUntilExpiry <=
                            $warningDays;
                }
                $driver->setAttribute(
                    'days_until_license_expiry',
                    $daysUntilExpiry
                );
                $driver->setAttribute(
                    'license_expired',
                    $licenseExpired
                );
                $driver->setAttribute(
                    'license_expiring_soon',
                    $licenseExpiringSoon
                );
                $driver->setAttribute(
                    'photo_url',
                    $driver->photo
                        ? asset('storage/'.Str::of($driver->photo)->trim('/'))
                        : null
                );

                return $driver;
            });

        return response()->json($drivers);
    }

    public function available()
    {
        $this->authorize('create', Driver::class);

        $drivers = Driver::whereNull('assigned_vehicle_id')
            ->orderBy('first_name')
            ->get([
                'id',
                'driver_number',
                'first_name',
                'last_name',
                'license_number',
            ]);

        return response()->json($drivers);
    }

    public function createAccount(Driver $driver)
    {
        $this->authorize('update', $driver);

        if ($driver->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'This driver already has a linked account.',
            ], 422);
        }

        if (! $driver->email) {
            return response()->json([
                'success' => false,
                'message' => 'Driver email is required before creating an account.',
            ], 422);
        }

        if (
            User::where(
                'email',
                $driver->email
            )->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' => 'An account with this email already exists.',
            ], 422);
        }

        $driverNumber =
            $driver->driver_number;

        if (! $driverNumber) {
            return response()->json([
                'success' => false,
                'message' => 'Driver number is missing.',
            ], 422);
        }

        $temporaryPassword =
            '#'.
            strtolower(
                str_replace(
                    '-',
                    '',
                    $driverNumber
                )
            );

        $createdUser =
            DB::transaction(
                function () use (
                    $driver,
                    $temporaryPassword
                ) {
                    $user =
                        User::query()->forceCreate([
                            'first_name' => $driver->first_name,

                            'last_name' => $driver->last_name,

                            'name' => trim(
                                $driver->first_name.
                                ' '.
                                $driver->last_name
                            ),

                            'email' => $driver->email,

                            'mobile_number' => $driver->contact_number,

                            'password' => Hash::make(
                                $temporaryPassword
                            ),

                            'role' => 'driver',

                            'job_title' => 'Driver',
                        ]);

                    $driver->forceFill([
                        'user_id' => $user->id,
                    ])->save();

                    $driver->refresh();

                    AuditLogService::log(
                        module: 'Driver Management',
                        action: 'Account Created',
                        description: "Created and linked an account for driver {$driver->driver_number}.",
                        record: $driver,
                        oldValues: [
                            'user_id' => null,
                        ],
                        newValues: [
                            'user_id' => $user->id,
                        ]
                    );

                    return $user;
                }
            );

        try {
            $createdUser->notify(
                new AccountCreatedNotification(
                    $temporaryPassword
                )
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Driver account created and linked successfully.',
        ]);
    }

    private function getDriverAuditValues(
        Driver $driver
    ): array {
        $driver->loadMissing(['qualifications', 'licenseCodes']);

        return [
            'driver_number' => $driver->driver_number,

            'first_name' => $driver->first_name,

            'last_name' => $driver->last_name,

            'license_number' => $driver->license_number,

            'license_class' => $driver->license_class,

            'dl_codes' => implode(', ', $driver->dl_codes),

            'clutch_authorization' => $driver->clutch_authorization,

            'license_expiry' => $driver->license_expiry
                    ? Carbon::parse(
                        $driver->license_expiry
                    )->format('Y-m-d')
                    : null,

            'email' => $driver->email,

            'contact_number' => $driver->contact_number,

            'experience' => $driver->experience,

            'assigned_vehicle_id' => $driver->assigned_vehicle_id,

            'status' => $driver->status,

            'user_id' => $driver->user_id,

            'authorized_vehicle_types' => $driver->qualifications
                ->pluck('vehicle_type')
                ->sort()
                ->values()
                ->implode(', '),
        ];
    }

    private function syncQualifications(Driver $driver, array $types): void
    {
        $types = collect($types)
            ->map(fn ($type) => trim((string) $type))
            ->filter(fn ($type) => in_array($type, Driver::VEHICLE_TYPE_OPTIONS, true))
            ->unique()
            ->values();

        $driver->qualifications()
            ->whereNotIn('vehicle_type', $types)
            ->delete();

        $types->each(fn ($type) => $driver->qualifications()->firstOrCreate([
            'vehicle_type' => $type,
        ]));
    }

    /** @param array<int, string> $codes */
    private function syncLicenseCodes(Driver $driver, array $codes): void
    {
        $codes = collect($codes)
            ->map(fn ($code) => trim((string) $code))
            ->filter(fn ($code) => in_array($code, Driver::DL_CODES, true))
            ->unique()
            ->values();

        $driver->licenseCodes()
            ->whereNotIn('code', $codes)
            ->delete();

        $codes->each(fn ($code) => $driver->licenseCodes()->firstOrCreate([
            'code' => $code,
        ]));
    }
}
