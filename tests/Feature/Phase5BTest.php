<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function phase5bUser(string $role, array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'first_name' => 'Test',
        'last_name' => 'User',
        'name' => 'Test User',
        'role' => $role,
        'status' => 'active',
    ], $attributes));
}

function phase5bUpdatePayload(User $user, array $overrides = []): array
{
    return array_merge([
        'first_name' => $user->first_name ?? 'Test',
        'middle_name' => $user->middle_name,
        'last_name' => $user->last_name ?? 'User',
        'email' => $user->email,
        'role' => $user->role,
        'department' => $user->department,
        'job_title' => $user->job_title,
        'mobile_number' => $user->mobile_number,
        'status' => $user->status,
    ], $overrides);
}

it('allows an IT admin to access the dedicated user management page', function () {
    $itAdmin = phase5bUser('it_admin');

    $this->actingAs($itAdmin)
        ->get(route('user-management.index'))
        ->assertSuccessful()
        ->assertSeeText('User Management')
        ->assertSee('userManagementTableBody', false)
        ->assertSee('class="modal-overlay"', false)
        ->assertSee('assets/js/user-management/account-management.js', false);
});

it('denies the user management page to unauthorized roles', function (string $role) {
    $this->actingAs(phase5bUser($role))
        ->get(route('user-management.index'))
        ->assertForbidden();
})->with([
    'Fleet Manager' => 'fleet_manager',
    'Dispatcher' => 'dispatcher',
    'Driver' => 'driver',
    'Maintenance' => 'maintenance',
    'Department Head' => 'department_head',
    'Finance' => 'finance',
]);

it('shows the sidebar link only to users with the view permission', function () {
    $itAdmin = phase5bUser('it_admin');

    $this->actingAs($itAdmin)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(route('user-management.index'), false)
        ->assertSeeText('User Management');

    foreach (['fleet_manager', 'dispatcher', 'driver', 'maintenance', 'department_head', 'finance'] as $role) {
        $this->actingAs(phase5bUser($role))
            ->get(route('dashboard'))
            ->assertSuccessful()
            ->assertDontSee(route('user-management.index'), false);
    }
});

it('removes account management from settings', function () {
    $itAdmin = phase5bUser('it_admin');

    $this->actingAs($itAdmin)
        ->get(route('settings'))
        ->assertSuccessful()
        ->assertDontSee('settingsAccounts', false)
        ->assertDontSee('settingsAccountTableBody', false)
        ->assertDontSee('account-management.js', false)
        ->assertDontSeeText('Account Management');
});

it('preserves create and directory flows with audit logging', function () {
    Notification::fake();
    $itAdmin = phase5bUser('it_admin');

    $this->actingAs($itAdmin)
        ->postJson(route('settings.accounts.store'), [
            'first_name' => 'Created',
            'middle_name' => 'By',
            'last_name' => 'Admin',
            'email' => 'created.user@example.test',
            'role' => 'finance',
            'department' => 'Finance',
            'job_title' => 'Analyst',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ])
        ->assertCreated()
        ->assertJsonPath('success', true);

    $createdUser = User::query()->where('email', 'created.user@example.test')->firstOrFail();

    expect($createdUser->role)->toBe('finance')
        ->and(Hash::check('SecurePassword123!', $createdUser->password))->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $itAdmin->id,
        'module' => 'Account Management',
        'action' => 'Created',
        'auditable_id' => $createdUser->id,
    ]);

    $this->actingAs($itAdmin)
        ->getJson(route('settings.accounts.index'))
        ->assertSuccessful()
        ->assertJsonFragment([
            'email' => 'created.user@example.test',
            'role' => 'finance',
        ]);
});

it('preserves account updates and separately authorizes role assignment', function () {
    Notification::fake();
    $itAdmin = phase5bUser('it_admin');
    $target = phase5bUser('finance', [
        'first_name' => 'Original',
        'last_name' => 'Name',
        'name' => 'Original Name',
    ]);

    $this->actingAs($itAdmin)
        ->patchJson(route('settings.accounts.update', $target), phase5bUpdatePayload($target, [
            'first_name' => 'Updated',
            'role' => 'dispatcher',
        ]))
        ->assertSuccessful();

    expect($target->fresh()->first_name)->toBe('Updated')
        ->and($target->fresh()->role)->toBe('dispatcher');

    config([
        'fleet_rbac.modules.settings.dispatcher' => 'full',
        'fleet_rbac.permissions.dispatcher' => ['users.view', 'users.update'],
    ]);

    $limitedAdministrator = phase5bUser('dispatcher');

    $this->actingAs($limitedAdministrator)
        ->patchJson(route('settings.accounts.update', $target), phase5bUpdatePayload($target, [
            'role' => 'finance',
        ]))
        ->assertForbidden();

    expect($target->fresh()->role)->toBe('dispatcher');
});

it('protects password reset with its fine grained update permission', function () {
    Notification::fake();
    $itAdmin = phase5bUser('it_admin');
    $target = phase5bUser('finance');

    $this->actingAs($itAdmin)
        ->postJson(route('settings.accounts.reset-password', $target), [
            'password' => 'ResetPassword123!',
            'password_confirmation' => 'ResetPassword123!',
        ])
        ->assertSuccessful();

    expect(Hash::check('ResetPassword123!', $target->fresh()->password))->toBeTrue();

    config([
        'fleet_rbac.modules.settings.dispatcher' => 'full',
        'fleet_rbac.permissions.dispatcher' => ['users.view'],
    ]);

    $this->actingAs(phase5bUser('dispatcher'))
        ->postJson(route('settings.accounts.reset-password', $target), [
            'password' => 'ForbiddenReset123!',
            'password_confirmation' => 'ForbiddenReset123!',
        ])
        ->assertForbidden();
});

it('preserves self protection for role status password and deactivation actions', function () {
    Notification::fake();
    $itAdmin = phase5bUser('it_admin');

    $this->actingAs($itAdmin)
        ->patchJson(route('settings.accounts.update', $itAdmin), phase5bUpdatePayload($itAdmin, [
            'role' => 'finance',
        ]))
        ->assertUnprocessable();

    $this->actingAs($itAdmin)
        ->postJson(route('settings.accounts.reset-password', $itAdmin), [
            'password' => 'SelfReset123!',
            'password_confirmation' => 'SelfReset123!',
        ])
        ->assertUnprocessable();

    $this->actingAs($itAdmin)
        ->deleteJson(route('settings.accounts.destroy', $itAdmin))
        ->assertUnprocessable();

    expect($itAdmin->fresh()->role)->toBe('it_admin')
        ->and($itAdmin->fresh()->status)->toBe('active');
});

it('protects the last active IT admin from demotion and deactivation', function () {
    Notification::fake();
    config([
        'fleet_rbac.modules.settings.dispatcher' => 'full',
        'fleet_rbac.permissions.dispatcher' => [
            'users.view',
            'users.update',
            'users.deactivate',
            'users.assign_role',
        ],
    ]);

    $operator = phase5bUser('dispatcher');
    $lastActiveItAdmin = phase5bUser('it_admin');

    $this->actingAs($operator)
        ->patchJson(
            route('settings.accounts.update', $lastActiveItAdmin),
            phase5bUpdatePayload($lastActiveItAdmin, [
                'role' => 'finance',
            ])
        )
        ->assertUnprocessable();

    $this->actingAs($operator)
        ->deleteJson(route('settings.accounts.destroy', $lastActiveItAdmin))
        ->assertUnprocessable();

    expect($lastActiveItAdmin->fresh()->role)->toBe('it_admin')
        ->and($lastActiveItAdmin->fresh()->status)->toBe('active');
});

it('deactivation and activation remain status changes and are audited', function () {
    Notification::fake();
    $itAdmin = phase5bUser('it_admin');
    $target = phase5bUser('finance');

    $this->actingAs($itAdmin)
        ->deleteJson(route('settings.accounts.destroy', $target))
        ->assertSuccessful();

    $this->assertDatabaseHas('users', [
        'id' => $target->id,
        'status' => 'inactive',
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'module' => 'Account Management',
        'action' => 'Deactivated',
        'auditable_id' => $target->id,
    ]);

    $this->actingAs($itAdmin)
        ->patchJson(route('settings.accounts.update', $target), phase5bUpdatePayload($target->fresh(), [
            'status' => 'active',
        ]))
        ->assertSuccessful();

    expect($target->fresh()->status)->toBe('active');
});

it('preserves driver role protections', function () {
    Notification::fake();
    $itAdmin = phase5bUser('it_admin');
    $normalUser = phase5bUser('finance');

    $this->actingAs($itAdmin)
        ->patchJson(route('settings.accounts.update', $normalUser), phase5bUpdatePayload($normalUser, [
            'role' => 'driver',
        ]))
        ->assertUnprocessable();

    $driver = phase5bUser('driver');

    $this->actingAs($itAdmin)
        ->patchJson(route('settings.accounts.update', $driver), [
            'middle_name' => $driver->middle_name,
            'email' => $driver->email,
            'role' => 'finance',
            'department' => $driver->department,
            'job_title' => $driver->job_title,
            'status' => 'active',
        ])
        ->assertSuccessful();

    expect($normalUser->fresh()->role)->toBe('finance')
        ->and($driver->fresh()->role)->toBe('driver');
});

it('rejects direct actions without fine grained permissions', function () {
    config([
        'fleet_rbac.modules.settings.dispatcher' => 'full',
        'fleet_rbac.permissions.dispatcher' => ['users.view'],
    ]);

    $dispatcher = phase5bUser('dispatcher');
    $target = phase5bUser('finance');

    $this->actingAs($dispatcher)
        ->postJson(route('settings.accounts.store'), [])
        ->assertForbidden();

    $this->actingAs($dispatcher)
        ->patchJson(route('settings.accounts.update', $target), phase5bUpdatePayload($target))
        ->assertForbidden();

    $this->actingAs($dispatcher)
        ->deleteJson(route('settings.accounts.destroy', $target))
        ->assertForbidden();
});

it('does not introduce a super admin role and rejects that assignment', function () {
    Notification::fake();
    $itAdmin = phase5bUser('it_admin');
    $target = phase5bUser('finance');

    expect(config('fleet_rbac.roles'))->not->toHaveKey('super_admin');

    $this->actingAs($itAdmin)
        ->patchJson(route('settings.accounts.update', $target), phase5bUpdatePayload($target, [
            'role' => 'super_admin',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');

    expect($target->fresh()->role)->toBe('finance');
});

it('keeps row account actions bound to their selected user without a replacement selector', function () {
    $script = file_get_contents(public_path('assets/js/user-management/account-management.js'));
    preg_match_all(
        '/if \(selectedUserId\) \{(.*?)await load(?:UpdateAccountForm|DeleteAccountDetails)\(selectedUserId\);\s*return;\s*\}/s',
        $script,
        $selectedRowBranches,
    );

    expect($script)
        ->toContain('accountManagementSelectedUserId = String(userId)')
        ->toContain('renderAccountUpdateForm(accountManagementSelectedUserId)')
        ->toContain('renderAccountResetPasswordForm(accountManagementSelectedUserId)')
        ->toContain('renderAccountDeleteForm(accountManagementSelectedUserId)')
        ->toContain('<div id="updateAccountFormContainer">')
        ->toContain('await loadUpdateAccountForm(selectedUserId)')
        ->toContain('<div id="deleteAccountDetails">')
        ->toContain('await loadDeleteAccountDetails(selectedUserId)')
        ->toContain('buildSelectedAccountIdentity(selectedUser)')
        ->toContain('form.dataset.userId')
        ->and($selectedRowBranches[0])->toHaveCount(2)
        ->and($selectedRowBranches[0][0])
        ->toContain('id="updateAccountFormContainer"')
        ->not->toContain('fetch("/settings/accounts"')
        ->and($selectedRowBranches[0][1])
        ->toContain('id="deleteAccountDetails"')
        ->not->toContain('fetch("/settings/accounts"');
});
