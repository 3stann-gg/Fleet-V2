<?php

use App\Models\Dispatch;
use App\Models\Driver;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\TwoFactorCodeNotification;
use App\Policies\DispatchPolicy;
use App\Policies\ReservationPolicy;
use App\Policies\VehiclePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

uses(RefreshDatabase::class);

it('enforces the final permission matrix for :role and :permission', function (
    string $role,
    string $permission,
    bool $expected
) {
    $user = User::factory()->make(['role' => $role]);

    expect($user->hasPermission($permission))->toBe($expected);
})->with([
    'fleet manager creates vehicles' => ['fleet_manager', 'vehicles.create', true],
    'fleet manager updates vehicles' => ['fleet_manager', 'vehicles.update', true],
    'fleet manager archives vehicles' => ['fleet_manager', 'vehicles.archive', true],
    'fleet manager creates drivers' => ['fleet_manager', 'drivers.create', true],
    'fleet manager updates drivers' => ['fleet_manager', 'drivers.update', true],
    'fleet manager manages reservations' => ['fleet_manager', 'reservations.manage_all', true],
    'fleet manager creates dispatches' => ['fleet_manager', 'dispatch.create', true],
    'fleet manager compares candidates' => ['fleet_manager', 'dispatch.candidate_comparison.view', true],
    'fleet manager manages maintenance' => ['fleet_manager', 'maintenance.update', true],
    'fleet manager views reports' => ['fleet_manager', 'reports.view', true],
    'fleet manager cannot manage users' => ['fleet_manager', 'users.create', false],
    'fleet manager cannot update settings' => ['fleet_manager', 'settings.update', false],
    'it admin views users' => ['it_admin', 'users.view', true],
    'it admin creates users' => ['it_admin', 'users.create', true],
    'it admin assigns roles' => ['it_admin', 'users.assign_role', true],
    'it admin updates settings' => ['it_admin', 'settings.update', true],
    'it admin views audit logs' => ['it_admin', 'audit_logs.view', true],
    'it admin cannot create dispatches' => ['it_admin', 'dispatch.create', false],
    'it admin cannot create maintenance' => ['it_admin', 'maintenance.create', false],
    'it admin cannot create fuel records' => ['it_admin', 'fuel.create', false],
    'dispatcher views vehicles' => ['dispatcher', 'vehicles.view', true],
    'dispatcher views drivers' => ['dispatcher', 'drivers.view', true],
    'dispatcher manages reservations' => ['dispatcher', 'reservations.manage_all', true],
    'dispatcher creates dispatches' => ['dispatcher', 'dispatch.create', true],
    'dispatcher manages routes' => ['dispatcher', 'routes.manage', true],
    'dispatcher cannot update vehicles' => ['dispatcher', 'vehicles.update', false],
    'dispatcher cannot update drivers' => ['dispatcher', 'drivers.update', false],
    'dispatcher cannot view users' => ['dispatcher', 'users.view', false],
    'dispatcher cannot update settings' => ['dispatcher', 'settings.update', false],
    'driver views assigned vehicle module' => ['driver', 'vehicles.view', true],
    'driver views dispatch module' => ['driver', 'dispatch.view', true],
    'driver completes pre trip checklist' => ['driver', 'pretrip.complete', true],
    'driver completes post trip checklist' => ['driver', 'posttrip.complete', true],
    'driver creates sanitation request' => ['driver', 'sanitation.create', true],
    'driver cannot create vehicles' => ['driver', 'vehicles.create', false],
    'driver cannot view cost analysis' => ['driver', 'cost_analysis.view', false],
    'driver cannot view audit logs' => ['driver', 'audit_logs.view', false],
    'maintenance views vehicles' => ['maintenance', 'vehicles.view', true],
    'maintenance updates permitted vehicle data' => ['maintenance', 'vehicles.update', true],
    'maintenance manages maintenance records' => ['maintenance', 'maintenance.update', true],
    'maintenance updates sanitation' => ['maintenance', 'sanitation.update', true],
    'maintenance updates battery health' => ['maintenance', 'battery_health.update', true],
    'maintenance cannot create dispatches' => ['maintenance', 'dispatch.create', false],
    'maintenance cannot manage users' => ['maintenance', 'users.update', false],
    'department head creates reservations' => ['department_head', 'reservations.create', true],
    'department head views department reservations' => ['department_head', 'reservations.view_department', true],
    'department head views dispatch status' => ['department_head', 'dispatch.view', true],
    'department head cannot create dispatches' => ['department_head', 'dispatch.create', false],
    'department head cannot view audit logs' => ['department_head', 'audit_logs.view', false],
    'finance views fuel transactions' => ['finance', 'fuel.view', true],
    'finance views charging transactions' => ['finance', 'charging.view', true],
    'finance views cost analysis' => ['finance', 'cost_analysis.view', true],
    'finance exports financial reports' => ['finance', 'reports.export', true],
    'finance cannot update vehicles' => ['finance', 'vehicles.update', false],
    'finance cannot create dispatches' => ['finance', 'dispatch.create', false],
    'finance cannot manage users' => ['finance', 'users.create', false],
]);

it('scopes dispatch records to the authenticated driver', function () {
    $user = User::factory()->create(['role' => 'driver']);
    $ownDriver = new Driver(['user_id' => $user->id]);
    $ownDriver->id = 10;
    $user->setRelation('driverProfile', $ownDriver);

    $ownDispatch = new Dispatch();
    $ownDispatch->setRelation('reservation', new Reservation(['driver_id' => 10]));

    $otherDispatch = new Dispatch();
    $otherDispatch->setRelation('reservation', new Reservation(['driver_id' => 11]));

    $policy = app(DispatchPolicy::class);

    expect($policy->view($user, $ownDispatch))->toBeTrue()
        ->and($policy->view($user, $otherDispatch))->toBeFalse()
        ->and($policy->completePreTrip($user, $otherDispatch))->toBeFalse()
        ->and($policy->completePostTrip($user, $otherDispatch))->toBeFalse();
});

it('scopes reservations to the authenticated department head', function () {
    $user = User::factory()->make([
        'role' => 'department_head',
        'department' => 'Emergency',
    ]);
    $own = new Reservation(['department' => 'Emergency', 'status' => 'Pending']);
    $other = new Reservation(['department' => 'Radiology', 'status' => 'Pending']);

    $policy = app(ReservationPolicy::class);

    expect($policy->view($user, $own))->toBeTrue()
        ->and($policy->view($user, $other))->toBeFalse()
        ->and($policy->update($user, $own))->toBeTrue()
        ->and($policy->update($user, $other))->toBeFalse();
});

it('scopes vehicle records to the authenticated driver assignment', function () {
    $user = User::factory()->create(['role' => 'driver']);
    $driver = new Driver(['user_id' => $user->id, 'assigned_vehicle_id' => 20]);
    $driver->id = 10;
    $user->setRelation('driverProfile', $driver);

    $ownVehicle = new Vehicle();
    $ownVehicle->id = 20;
    $otherVehicle = new Vehicle();
    $otherVehicle->id = 21;

    $policy = app(VehiclePolicy::class);

    expect($policy->view($user, $ownVehicle))->toBeTrue()
        ->and($policy->view($user, $otherVehicle))->toBeFalse();
});

it('denies dispatcher access to user administration and role assignment', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $target = User::factory()->create(['role' => 'finance']);

    $this->actingAs($dispatcher)
        ->patchJson(route('settings.accounts.update', $target), [
            'role' => 'it_admin',
        ])
        ->assertForbidden();

    expect(Gate::forUser($dispatcher)->allows('users.assign_role'))->toBeFalse();
});

it('denies fleet manager settings and account administration', function () {
    $fleetManager = User::factory()->create(['role' => 'fleet_manager']);

    expect(Gate::forUser($fleetManager)->allows('settings.view'))->toBeFalse()
        ->and(Gate::forUser($fleetManager)->allows('users.view'))->toBeFalse();
});

it('rejects inactive authenticated sessions', function () {
    $user = User::factory()->create([
        'role' => 'fleet_manager',
        'status' => 'inactive',
    ]);

    $this->actingAs($user)
        ->getJson(route('dashboard.data'))
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_INACTIVE');
});

it('allows active authorized sessions through fleet middleware', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    expect($user->fresh()->status)->toBe('active');

    $this->actingAs($user)
        ->getJson(route('settings.data'))
        ->assertSuccessful();
});

it('denies an unauthorized report export and bulk action', function () {
    $driver = User::factory()->create(['role' => 'driver']);
    $finance = User::factory()->create(['role' => 'finance']);

    $this->actingAs($driver)
        ->postJson(route('reports.audit-export'), [
            'format' => 'print',
            'report_type' => 'overview',
        ])
        ->assertForbidden();

    $this->actingAs($finance)
        ->postJson(route('vehicles.bulkArchive'), ['ids' => [1]])
        ->assertForbidden();
});

it('removes public registration and production AI recommendation routes', function () {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('dispatch.recommendation'))->toBeFalse()
        ->and(Route::has('dispatch.reassignments.recommendation'))->toBeFalse()
        ->and(Route::has('reservation.apply-dispatch-recommendation'))->toBeFalse();
});

it('rejects non-canonical role values in account administration', function () {
    $validator = Validator::make(
        ['role' => 'super_admin'],
        ['role' => ['required', Rule::in(array_keys(config('fleet_rbac.roles')))]]
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('role'))->toBeTrue();
});

it('audits an authorized canonical role assignment', function () {
    Notification::fake();
    $itAdmin = User::factory()->create(['role' => 'it_admin']);
    $target = User::factory()->create([
        'first_name' => 'Target',
        'last_name' => 'Account',
        'name' => 'Target Account',
        'role' => 'finance',
    ]);

    $this->actingAs($itAdmin)
        ->patchJson(route('settings.accounts.update', $target), [
            'first_name' => 'Target',
            'last_name' => 'Account',
            'email' => $target->email,
            'role' => 'dispatcher',
            'status' => 'active',
        ])
        ->assertSuccessful();

    expect($target->fresh()->role)->toBe('dispatcher');
    $this->assertDatabaseHas('audit_logs', [
        'module' => 'Account Management',
        'action' => 'Updated',
    ]);
});

it('prevents an IT administrator from changing their own role or status', function () {
    Notification::fake();
    $itAdmin = User::factory()->create([
        'first_name' => 'System',
        'last_name' => 'Admin',
        'name' => 'System Admin',
        'role' => 'it_admin',
    ]);

    $this->actingAs($itAdmin)
        ->patchJson(route('settings.accounts.update', $itAdmin), [
            'first_name' => 'System',
            'last_name' => 'Admin',
            'email' => $itAdmin->email,
            'role' => 'fleet_manager',
            'status' => 'active',
        ])
        ->assertUnprocessable();

    expect($itAdmin->fresh()->role)->toBe('it_admin');
});

it('does not mass assign role or status on the user model', function () {
    $user = new User();
    $user->fill([
        'name' => 'Mass Assignment Attempt',
        'role' => 'it_admin',
        'status' => 'inactive',
    ]);

    expect($user->role)->toBeNull()
        ->and($user->status)->toBeNull();
});

it('starts two factor verification for an active valid login', function () {
    Notification::fake();
    $user = User::factory()->create(['role' => 'dispatcher']);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertRedirect(route('two-factor'))
        ->assertSessionHas('two_factor_user_id', $user->id);

    $this->assertGuest();
    Notification::assertSentTo($user, TwoFactorCodeNotification::class);
});

it('rejects an inactive account before two factor verification', function () {
    Notification::fake();
    $user = User::factory()->create([
        'role' => 'dispatcher',
        'status' => 'inactive',
    ]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertSessionHasErrors('email')
        ->assertSessionMissing('two_factor_user_id');

    $this->assertGuest();
    Notification::assertNothingSent();
});

it('denies finance direct access to candidate comparison', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $reservation = Reservation::query()->create([
        'reservation_number' => 'RBAC-FINANCE-001',
        'patient_name' => 'Protected Request',
        'request_type' => 'Patient Transport',
        'pickup_location' => 'Hospital',
        'destination' => 'Clinic',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00:00',
        'priority' => 'Normal',
        'status' => 'Approved',
    ]);

    $this->actingAs($finance)
        ->getJson(route('dispatch.candidate-vehicles', $reservation))
        ->assertForbidden();
});

it('denies IT admin operational mutation despite read access', function () {
    $itAdmin = User::factory()->create(['role' => 'it_admin']);

    $this->actingAs($itAdmin)
        ->postJson(route('vehicles.store'), [])
        ->assertForbidden();
});
