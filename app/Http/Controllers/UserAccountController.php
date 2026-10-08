<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogService;
use App\Notifications\AccountCreatedNotification;
use App\Notifications\AccountUpdatedNotification;
use App\Notifications\PasswordResetByAdminNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserAccountController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        Gate::authorize('users.view');

        $users = User::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get([
                'id',
                'first_name',
                'middle_name',
                'last_name',
                'name',
                'email',
                'role',
                'department',
                'job_title',
                'mobile_number',
                'status',
            ]);

        return response()->json([
            'users' => $users,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        Gate::authorize('users.create');
        Gate::authorize('users.assign_role');

        $validated =
            $request->validate([
                'first_name' => [
                    'required',
                    'string',
                    'max:60',
                ],

                'middle_name' => [
                    'nullable',
                    'string',
                    'max:60',
                ],

                'last_name' => [
                    'required',
                    'string',
                    'max:60',
                ],

                'email' => [
                    'required',
                    'email',
                    'max:120',
                    Rule::unique(
                        'users',
                        'email'
                    ),
                ],

                'role' => [
                    'required',
                    Rule::in(array_keys(config('fleet_rbac.roles'))),
                ],

                'department' => [
                    'nullable',
                    'string',
                    'max:120',
                ],

                'job_title' => [
                    'nullable',
                    'string',
                    'max:120',
                ],

                'password' => [
                    'required',
                    'string',
                    'min:8',
                    'confirmed',
                ],
            ]);

        $plainPassword =
            $validated['password'];

        $createdUser =
            User::query()->forceCreate([
                'first_name' =>
                    $validated[
                        'first_name'
                    ],

                'middle_name' =>
                    $validated[
                        'middle_name'
                    ] ?? null,

                'last_name' =>
                    $validated[
                        'last_name'
                    ],

                'name' =>
                    trim(
                        $validated[
                            'first_name'
                        ] .
                        ' ' .
                        $validated[
                            'last_name'
                        ]
                    ),

                'email' =>
                    $validated[
                        'email'
                    ],

                'role' =>
                    $validated[
                        'role'
                    ],

                'department' =>
                    $validated[
                        'department'
                    ] ?? null,

                'job_title' =>
                    $validated[
                        'job_title'
                    ] ?? null,

                'password' =>
                    Hash::make(
                        $plainPassword
                    ),
            ]);
        
        AuditLogService::log(
            module: 'Account Management',
            action: 'Created',
            description:
                "Created user account {$createdUser->name}.",
            record: $createdUser,
            newValues: [
                'name' =>
                    $createdUser->name,

                'email' =>
                    $createdUser->email,

                'role' =>
                    $createdUser->role,

                'department' =>
                    $createdUser->department,

                'job_title' =>
                    $createdUser->job_title,

                'status' =>
                    $createdUser->status,
            ]
        );

            try {
                $createdUser->notify(
                    new AccountCreatedNotification(
                        $plainPassword
                    )
                );
            } catch (\Throwable $e) {
                report($e);
            }

        return response()->json([
            'success' => true,

            'message' =>
                'User account created successfully.',

            'user' => [
                'id' =>
                    $createdUser->id,

                'name' =>
                    $createdUser->name,

                'email' =>
                    $createdUser->email,

                'role' =>
                    $createdUser->role,
            ],
        ], 201);
    }

    public function show(
        Request $request,
        User $user
    ) {
        $currentUser = $request->user();

        Gate::authorize('users.view');

        return response()->json([
            'user' => [
                'id' =>
                    $user->id,

                'first_name' =>
                    $user->first_name,

                'middle_name' =>
                    $user->middle_name,

                'last_name' =>
                    $user->last_name,

                'name' =>
                    $user->name,

                'email' =>
                    $user->email,

                'role' =>
                    $user->role,

                'department' =>
                    $user->department,

                'job_title' =>
                    $user->job_title,

                'mobile_number' =>
                    $user->mobile_number,

                'status' =>
                    $user->status,
            ],
        ]);
    }

    public function update(
        Request $request,
        User $user
    ) {
        $currentUser =
            $request->user();

        Gate::authorize('users.update');

        $rules = [
            'first_name' => [
                'required',
                'string',
                'max:60',
            ],

            'middle_name' => [
                'nullable',
                'string',
                'max:60',
            ],

            'last_name' => [
                'required',
                'string',
                'max:60',
            ],

            'email' => [
                'required',
                'email',
                'max:120',

                Rule::unique(
                    'users',
                    'email'
                )->ignore(
                    $user->id
                ),
            ],

            'role' => [
                'required',

                Rule::in(array_keys(config('fleet_rbac.roles'))),
            ],

            'department' => [
                'nullable',
                'string',
                'max:120',
            ],

            'job_title' => [
                'nullable',
                'string',
                'max:120',
            ],

            'mobile_number' => [
                'nullable',
                'string',
                'max:40',
            ],

            'status' => [
                'nullable',

                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Driver-managed fields
        |--------------------------------------------------------------------------
        |
        | Name/mobile come from Driver module,
        | so Account Management does not require or modify them.
        |--------------------------------------------------------------------------
        */
        if ($user->role === 'driver') {
            unset(
                $rules['first_name'],
                $rules['last_name'],
                $rules['mobile_number']
            );
        }

        $validated =
            $request->validate(
                $rules
            );

        if (($validated['role'] ?? $user->role) !== $user->role) {
            Gate::authorize('users.assign_role');
        }

        if (($validated['status'] ?? $user->status) !== $user->status) {
            Gate::authorize(
                ($validated['status'] ?? null) === 'active'
                    ? 'users.activate'
                    : 'users.deactivate'
            );
        }

        if (
            $currentUser->is($user)
            && (
                ($validated['role'] ?? $user->role) !== $user->role
                || ($validated['status'] ?? $user->status) !== $user->status
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot change your own role or account status.',
            ], 422);
        }

        $removesLastActiveItAdmin =
            $user->role === 'it_admin'
            && $user->status === 'active'
            && (
                ($validated['role'] ?? $user->role) !== 'it_admin'
                || ($validated['status'] ?? $user->status) !== 'active'
            )
            && !User::query()
                ->whereKeyNot($user->id)
                ->where('role', 'it_admin')
                ->where('status', 'active')
                ->exists();

        if ($removesLastActiveItAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'The last active IT administrator cannot be demoted or deactivated.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent converting a normal account into Driver manually
        |--------------------------------------------------------------------------
        */
        if (
            isset($validated['role']) &&
            $validated['role'] === 'driver' &&
            $user->role !== 'driver'
        ) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Driver accounts must be created from the Driver module.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Preserve Driver role
        |--------------------------------------------------------------------------
        */
        if ($user->role === 'driver') {
            $validated['role'] =
                'driver';
        }

        /*
        |--------------------------------------------------------------------------
        | Rebuild display name for non-Driver accounts
        |--------------------------------------------------------------------------
        */
        if (
            isset(
                $validated['first_name'],
                $validated['last_name']
            )
        ) {
            $validated['name'] =
                trim(
                    $validated['first_name'] .
                    ' ' .
                    $validated['last_name']
                );
        }

        $auditFields =
            array_keys($validated);
        $oldValues =
            $user->only(
                $auditFields
            );

        $user->forceFill($validated)->save();

        $newValues =
            $user->fresh()->only(
                $auditFields
            );

        $changedOldValues = [];
        $changedNewValues = [];

        foreach ($auditFields as $field) {
            $oldValue =
                $oldValues[$field] ?? null;

            $newValue =
                $newValues[$field] ?? null;

            if ($oldValue != $newValue) {
                $changedOldValues[$field] =
                    $oldValue;

                $changedNewValues[$field] =
                    $newValue;
            }
        }

if (!empty($changedNewValues)) {
    AuditLogService::log(
        module: 'Account Management',
        action: 'Updated',
        description:
            "Updated user account {$user->name}.",
        record: $user,
        oldValues: $changedOldValues,
        newValues: $changedNewValues
    );
}

        /*
        |--------------------------------------------------------------------------
        | Account Updated Email
        |--------------------------------------------------------------------------
        */
        try {
            $user->notify(
                new AccountUpdatedNotification()
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'success' => true,

            'message' =>
                'User account updated successfully.',

            'user' => [
                'id' =>
                    $user->id,

                'name' =>
                    $user->name,

                'email' =>
                    $user->email,

                'role' =>
                    $user->role,
            ],
        ]);
    }

    public function resetPassword(
        Request $request,
        User $user
    ) {
        $currentUser =
            $request->user();

        Gate::authorize('users.update');

        if ($currentUser->is($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Use your profile security settings to change your own password.',
            ], 422);
        }

        $validated =
            $request->validate([
                'password' => [
                    'required',
                    'string',
                    'min:8',
                    'confirmed',
                ],
            ]);

        $plainPassword =
            $validated['password'];

        $user->update([
            'password' =>
                Hash::make(
                    $plainPassword
                ),
        ]);

        AuditLogService::log(
            module: 'Account Management',
            action: 'Password Reset',
            description:
                "Reset the password for user account {$user->name}.",
            record: $user
        );

        try {
            $user->notify(
                new PasswordResetByAdminNotification(
                    $plainPassword
                )
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'success' => true,
            'message' =>
                'Password reset successfully.',
        ]);
    }

    public function destroy(
        Request $request,
        User $user
    ) {
        $currentUser =
            $request->user();

        Gate::authorize('users.deactivate');

        if (
            $currentUser->id ===
            $user->id
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'You cannot deactivate your own account from Account Management.',
            ], 422);
        }

        if (
            $user->role === 'it_admin'
            && $user->status === 'active'
            && !User::query()
                ->whereKeyNot($user->id)
                ->where('role', 'it_admin')
                ->where('status', 'active')
                ->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The last active IT administrator cannot be deactivated.',
            ], 422);
        }

        $oldValues = $user->only(['status']);
        $user->forceFill(['status' => 'inactive'])->save();

        AuditLogService::log(
            module: 'Account Management',
            action: 'Deactivated',
            description: "Deactivated user account {$user->name}.",
            record: $user,
            oldValues: $oldValues,
            newValues: ['status' => 'inactive']
        );

        return response()->json([
            'success' => true,
            'message' =>
                'User account deactivated successfully.',
        ]);
    }
}
