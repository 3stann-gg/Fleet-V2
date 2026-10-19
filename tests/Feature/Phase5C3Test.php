<?php

use App\Models\Dispatch;
use App\Models\DispatchReassignment;
use App\Models\Driver;
use App\Models\DriverVehicleQualification;
use App\Models\Reservation;
use App\Models\RoutePlan;
use App\Models\TrafficViolation;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

function p5c3User(string $role = 'fleet_manager', array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'first_name' => 'Phase', 'last_name' => 'Five C Three', 'name' => 'Phase Five C Three',
        'role' => $role, 'status' => 'active',
    ], $attributes));
}

/** @return array{0: Vehicle, 1: Driver} */
function p5c3Pair(?User $driverUser = null, bool $qualified = true): array
{
    $vehicle = Vehicle::query()->create([
        'plate_number' => fake()->unique()->bothify('C3-####'), 'vehicle_type' => 'Service Vehicle',
        'lto_vehicle_category' => 'M1', 'department' => 'Operations', 'brand' => 'Phase',
        'model' => 'Five C Three', 'capacity' => 4, 'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline', 'tank_capacity' => 50, 'current_fuel' => 35,
        'current_odometer' => 100, 'transmission_type' => 'automatic',
        'registration_expiry' => now()->addYear()->toDateString(),
        'insurance_expiry' => now()->addYear()->toDateString(),
        'status' => 'Available', 'readiness_status' => 'ready',
    ]);
    $driver = Driver::query()->create([
        'user_id' => $driverUser?->id, 'first_name' => 'Phase',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('C3-LIC-####'),
        'license_class' => 'Professional', 'clutch_authorization' => 'AT',
        'license_expiry' => now()->addYear()->toDateString(), 'contact_number' => '09170000000',
        'email' => fake()->unique()->safeEmail(), 'assigned_vehicle_id' => $vehicle->id,
        'status' => 'Available',
    ]);

    if ($qualified) {
        $driver->licenseCodes()->create(['code' => 'B']);
        DriverVehicleQualification::query()->create([
            'driver_id' => $driver->id, 'vehicle_type' => $vehicle->vehicle_type,
        ]);
    }

    return [$vehicle, $driver];
}

function p5c3Reservation(array $attributes = []): Reservation
{
    [$vehicle, $driver] = p5c3Pair();

    return Reservation::query()->create(array_merge([
        'reservation_number' => fake()->unique()->bothify('C3-RSV-####'),
        'patient_name' => null, 'request_type' => 'administrative_trip',
        'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id,
        'pickup_location' => 'Hospital', 'destination' => 'Clinic',
        'schedule_date' => now()->addDay()->toDateString(), 'schedule_time' => '09:00:00',
        'priority' => 'routine', 'status' => 'Approved',
    ], $attributes));
}

function p5c3Route(Reservation $reservation, array $attributes = []): RoutePlan
{
    return RoutePlan::query()->create(array_merge([
        'route_number' => fake()->unique()->bothify('C3-ROUTE-####'),
        'reservation_id' => $reservation->id, 'origin' => 'Hospital', 'destination' => 'Clinic',
        'priority' => $reservation->priority, 'department' => 'Operations', 'status' => 'Draft',
        'departure_date' => now()->addDay()->toDateString(), 'departure_time' => '09:00:00',
    ], $attributes));
}

function p5c3RoutePayload(RoutePlan $routePlan, array $attributes = []): array
{
    return array_merge([
        'origin' => $routePlan->origin, 'origin_latitude' => 14.5995, 'origin_longitude' => 120.9842,
        'destination' => $routePlan->destination, 'destination_latitude' => 14.6091,
        'destination_longitude' => 121.0223, 'department' => $routePlan->department,
        'status' => $routePlan->status, 'estimated_distance' => 12.5, 'estimated_time' => 28,
        'optimization_strategy' => 'TomTom Traffic-Aware Fastest', 'optimization_score' => 94,
        'optimization_applied' => false, 'purpose' => 'Patient transport', 'notes' => null, 'stops' => [],
    ], $attributes);
}

function p5c3EmergencyProof(User $user, Reservation $reservation, array $payload): string
{
    return Crypt::encryptString(json_encode([
        'user_id' => $user->id, 'reservation_id' => $reservation->id, 'issued_at' => now()->timestamp,
        'origin_latitude' => $payload['origin_latitude'], 'origin_longitude' => $payload['origin_longitude'],
        'destination_latitude' => $payload['destination_latitude'],
        'destination_longitude' => $payload['destination_longitude'],
        'coordinates' => [
            ['latitude' => $payload['origin_latitude'], 'longitude' => $payload['origin_longitude']],
            ['latitude' => $payload['destination_latitude'], 'longitude' => $payload['destination_longitude']],
        ],
        'estimated_distance' => $payload['estimated_distance'], 'estimated_time' => $payload['estimated_time'],
        'optimization_strategy' => $payload['optimization_strategy'], 'provider' => 'tomtom',
    ], JSON_THROW_ON_ERROR));
}

function p5c3ViolationPayload(array $attributes = []): array
{
    return array_merge([
        'citation_number' => fake()->unique()->bothify('C3-CIT-####'),
        'violation_date' => now()->toDateString(), 'violation_type' => 'speeding',
        'description' => 'Lifecycle audit citation.',
    ], $attributes);
}

function p5c3ReviewPayload(array $attributes = []): array
{
    return array_merge([
        'responsibility' => 'under_review', 'responsibility_notes' => null,
        'status' => 'under_review', 'fine_amount' => null, 'due_date' => null,
        'payment_status' => 'not_applicable', 'payment_date' => null,
        'payment_reference' => null, 'settlement_notes' => null,
    ], $attributes);
}

/** @return array{0: Dispatch, 1: DispatchReassignment, 2: Vehicle, 3: Driver} */
function p5c3Reassignment(bool $qualifiedReplacement = true): array
{
    $driverUser = p5c3User('driver');
    [$originalVehicle, $originalDriver] = p5c3Pair($driverUser);
    [$replacementVehicle, $replacementDriver] = p5c3Pair(null, $qualifiedReplacement);
    $reservation = p5c3Reservation([
        'vehicle_id' => $originalVehicle->id, 'driver_id' => $originalDriver->id,
    ]);
    p5c3Route($reservation, ['status' => 'Ready For Dispatch']);
    $dispatch = Dispatch::query()->create([
        'dispatch_number' => fake()->unique()->bothify('C3-DSP-####'),
        'reservation_id' => $reservation->id, 'dispatch_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00', 'trip_status' => 'Pending',
    ]);
    $reassignment = DispatchReassignment::query()->create([
        'dispatch_id' => $dispatch->id, 'requested_by' => $driverUser->id,
        'reason' => 'Driver is unavailable for the scheduled assignment.', 'status' => 'Requested',
        'original_vehicle_id' => $originalVehicle->id, 'original_driver_id' => $originalDriver->id,
    ]);

    return [$dispatch, $reassignment, $replacementVehicle, $replacementDriver];
}

function p5c3AccountPayload(User $user, array $attributes = []): array
{
    return array_merge([
        'first_name' => $user->first_name ?? 'Phase', 'middle_name' => $user->middle_name,
        'last_name' => $user->last_name ?? 'User', 'email' => $user->email, 'role' => $user->role,
        'department' => $user->department, 'job_title' => $user->job_title,
        'mobile_number' => $user->mobile_number, 'status' => $user->status,
    ], $attributes);
}

it('creates route plans in the server-owned Draft state with authoritative priority', function () {
    $reservation = p5c3Reservation(['priority' => 'urgent']);
    $this->actingAs(p5c3User())->postJson(route('route-planning.store'), [
        'reservation_id' => $reservation->id, 'department' => 'Operations',
        'status' => 'Ready For Dispatch', 'priority' => 'scheduled',
    ])->assertCreated()->assertJsonPath('routePlan.status', 'Draft')
        ->assertJsonPath('routePlan.priority', 'urgent');
});

it('cannot inject protected route status :status during creation', function (string $status) {
    $reservation = p5c3Reservation();
    $this->actingAs(p5c3User())->postJson(route('route-planning.store'), [
        'reservation_id' => $reservation->id, 'department' => 'Operations', 'status' => $status,
    ])->assertCreated()->assertJsonPath('routePlan.status', 'Draft');
})->with(['Ready For Dispatch', 'Completed', 'Archived']);

it('generic route edits cannot force protected status :status', function (string $status) {
    $routePlan = p5c3Route(p5c3Reservation());
    $this->actingAs(p5c3User())
        ->putJson(route('route-planning.update', $routePlan), p5c3RoutePayload($routePlan, ['status' => $status]))
        ->assertUnprocessable()->assertJsonValidationErrors('status');
    expect($routePlan->fresh()->status)->toBe('Draft');
})->with(['Ready For Dispatch', 'Completed', 'Archived']);

it('uses the dedicated workflow action for legitimate planning and priority synchronization', function () {
    $reservation = p5c3Reservation(['priority' => 'High']);
    $routePlan = p5c3Route($reservation);
    $this->actingAs(p5c3User())
        ->postJson(route('route-planning.transition', $routePlan), p5c3RoutePayload($routePlan, [
            'status' => 'Planned', 'priority' => 'Emergency',
        ]))->assertSuccessful()->assertJsonPath('routePlan.status', 'Planned')
        ->assertJsonPath('routePlan.priority', 'urgent');
});

it('supports the normal Plan Optimize Review Ready workflow through the dedicated action', function () {
    $routePlan = p5c3Route(p5c3Reservation(), ['status' => 'Planned']);
    $this->actingAs(p5c3User())
        ->postJson(route('route-planning.transition', $routePlan), p5c3RoutePayload($routePlan, [
            'status' => 'Ready For Dispatch',
        ]))->assertSuccessful()->assertJsonPath('routePlan.status', 'Ready For Dispatch');
});

it('keeps archive as a dedicated route action', function () {
    $routePlan = p5c3Route(p5c3Reservation());
    $this->actingAs(p5c3User())->postJson(route('route-planning.archive', $routePlan))
        ->assertSuccessful()->assertJsonPath('routePlan.status', 'Archived');
});

it('does not allow historical route status :status to regress through generic edit', function (string $status) {
    $routePlan = p5c3Route(p5c3Reservation(), ['status' => $status]);
    $this->actingAs(p5c3User())
        ->putJson(route('route-planning.update', $routePlan), p5c3RoutePayload($routePlan))
        ->assertUnprocessable();
    expect($routePlan->fresh()->status)->toBe($status);
})->with(['Completed', 'Archived']);

it('promotes an emergency route only with a matching encrypted optimization proof', function () {
    $reservation = p5c3Reservation([
        'request_type' => 'emergency_response', 'patient_name' => 'Emergency Patient', 'priority' => 'emergency',
    ]);
    $routePlan = p5c3Route($reservation, ['status' => 'Planned']);
    $user = p5c3User();
    $payload = p5c3RoutePayload($routePlan, ['status' => 'Planned', 'optimization_applied' => true]);
    $payload['optimization_proof'] = p5c3EmergencyProof($user, $reservation, $payload);
    $this->actingAs($user)->postJson(route('route-planning.transition', $routePlan), $payload)
        ->assertSuccessful()->assertJsonPath('routePlan.status', 'Ready For Dispatch');
});

it('rejects untrusted emergency optimization promotion', function () {
    $reservation = p5c3Reservation([
        'request_type' => 'emergency_response', 'patient_name' => 'Emergency Patient', 'priority' => 'emergency',
    ]);
    $routePlan = p5c3Route($reservation, ['status' => 'Planned']);
    $this->actingAs(p5c3User())->postJson(route('route-planning.transition', $routePlan), p5c3RoutePayload($routePlan, [
        'status' => 'Planned', 'optimization_applied' => true,
        'optimization_proof' => 'fallback-or-forged-proof',
    ]))->assertUnprocessable();
    expect($routePlan->fresh()->status)->toBe('Planned');
});

it('preserves the Phase 5A4 route workflow UI contract', function () {
    $this->actingAs(p5c3User())->get(route('route-planning'))->assertSuccessful()
        ->assertSeeInOrder(['Plan', 'Optimize', 'Review', 'Ready for Dispatch']);
    expect(file_get_contents(public_path('assets/js/route-planning/route-modal.js')))
        ->toContain('transitionRoutePlanApi')->toContain('optimization_proof: data.optimizationProof');
});

it('creates violations in the server-owned reported review state', function () {
    $this->actingAs(p5c3User())->postJson(route('traffic-violations.store'), p5c3ViolationPayload([
        'responsibility' => 'driver', 'status' => 'closed', 'payment_status' => 'paid',
    ]))->assertCreated()->assertJsonPath('traffic_violation.responsibility', 'under_review')
        ->assertJsonPath('traffic_violation.status', 'reported')
        ->assertJsonPath('traffic_violation.payment_status', 'not_applicable');
});

it('generic violation edits cannot inject review lifecycle fields', function () {
    $violation = TrafficViolation::factory()->create();
    $this->actingAs(p5c3User('dispatcher'))->putJson(route('traffic-violations.update', $violation),
        p5c3ViolationPayload(['responsibility' => 'driver', 'status' => 'settled', 'payment_status' => 'paid']))
        ->assertSuccessful();
    expect($violation->fresh()->responsibility)->toBe('under_review')
        ->and($violation->fresh()->status)->toBe('reported')
        ->and($violation->fresh()->payment_status)->toBe('not_applicable');
});

it('permits a valid dedicated human review transition and responsibility decision', function () {
    $violation = TrafficViolation::factory()->create();
    $this->actingAs(p5c3User())->putJson(route('traffic-violations.review', $violation),
        p5c3ReviewPayload(['responsibility' => 'hospital_fleet', 'status' => 'under_review']))
        ->assertSuccessful();
    expect($violation->fresh()->status)->toBe('under_review')
        ->and($violation->fresh()->responsibility)->toBe('hospital_fleet')
        ->and($violation->fresh()->reviewed_at)->not->toBeNull();
});

it('rejects an invalid traffic violation transition', function () {
    $violation = TrafficViolation::factory()->create(['status' => 'reported']);
    $this->actingAs(p5c3User())->putJson(route('traffic-violations.review', $violation),
        p5c3ReviewPayload(['status' => 'settled']))->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('prevents terminal violation state :status from regressing', function (string $status) {
    $violation = TrafficViolation::factory()->create(['status' => $status]);
    $this->actingAs(p5c3User())->putJson(route('traffic-violations.review', $violation),
        p5c3ReviewPayload(['status' => 'under_review']))->assertUnprocessable()->assertJsonValidationErrors('status');
    expect($violation->fresh()->status)->toBe($status);
})->with(['settled', 'dismissed', 'closed']);

it('keeps payment data informational and separate from review status', function () {
    $violation = TrafficViolation::factory()->create(['status' => 'for_settlement']);
    $this->actingAs(p5c3User('finance'))->putJson(route('traffic-violations.settlement', $violation), [
        'fine_amount' => 500, 'payment_status' => 'paid', 'payment_date' => now()->toDateString(),
        'payment_reference' => 'OR-C3', 'settlement_notes' => 'Receipt recorded.',
    ])->assertSuccessful();
    expect($violation->fresh()->payment_status)->toBe('paid')
        ->and($violation->fresh()->status)->toBe('for_settlement');
});

it('denies protected violation review to an unauthorized role', function () {
    $violation = TrafficViolation::factory()->create();
    $this->actingAs(p5c3User('dispatcher'))->putJson(route('traffic-violations.review', $violation),
        p5c3ReviewPayload())->assertForbidden();
});

it('creates reassignment requests in server-owned Requested state despite crafted status :status', function (string $status) {
    $driverUser = p5c3User('driver');
    [$vehicle, $driver] = p5c3Pair($driverUser);
    $reservation = p5c3Reservation(['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id]);
    p5c3Route($reservation, ['status' => 'Ready For Dispatch']);
    $dispatch = Dispatch::query()->create([
        'dispatch_number' => fake()->unique()->bothify('C3-DSP-####'),
        'reservation_id' => $reservation->id, 'dispatch_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00', 'trip_status' => 'Pending',
    ]);
    $this->actingAs($driverUser)->postJson(route('dispatch.request-reassignment', $dispatch), [
        'reason' => 'Unable to complete this assignment safely.', 'status' => $status,
    ])->assertSuccessful();
    expect($dispatch->reassignments()->sole()->status)->toBe('Requested');
})->with(['Requested', 'Approved', 'Rejected']);

it('approves a valid reassignment and atomically updates both assignment IDs', function () {
    [$dispatch, $reassignment, $vehicle, $driver] = p5c3Reassignment();
    $this->actingAs(p5c3User('dispatcher'))->postJson(route('dispatch.reassignments.approve', $reassignment), [
        'new_vehicle_id' => $vehicle->id, 'new_driver_id' => $driver->id,
    ])->assertSuccessful();
    expect($reassignment->fresh()->status)->toBe('Approved')
        ->and($dispatch->reservation->fresh()->vehicle_id)->toBe($vehicle->id)
        ->and($dispatch->reservation->fresh()->driver_id)->toBe($driver->id);
});

it('rejects a reassignment through its dedicated action', function () {
    [, $reassignment] = p5c3Reassignment();
    $this->actingAs(p5c3User('dispatcher'))->postJson(route('dispatch.reassignments.reject', $reassignment))
        ->assertSuccessful();
    expect($reassignment->fresh()->status)->toBe('Rejected');
});

it('denies reassignment review action :action to an unauthorized driver', function (string $action) {
    [, $reassignment, $vehicle, $driver] = p5c3Reassignment();
    $route = route("dispatch.reassignments.{$action}", $reassignment);
    $payload = $action === 'approve'
        ? ['new_vehicle_id' => $vehicle->id, 'new_driver_id' => $driver->id] : [];
    $this->actingAs(p5c3User('driver'))->postJson($route, $payload)->assertForbidden();
})->with(['approve', 'reject']);

it('does not allow reviewed reassignment state :status to regress', function (string $status) {
    [, $reassignment, $vehicle, $driver] = p5c3Reassignment();
    $reassignment->update(['status' => $status]);
    $this->actingAs(p5c3User('dispatcher'))->postJson(route('dispatch.reassignments.approve', $reassignment), [
        'new_vehicle_id' => $vehicle->id, 'new_driver_id' => $driver->id,
    ])->assertUnprocessable();
    expect($reassignment->fresh()->status)->toBe($status);
})->with(['Approved', 'Rejected']);

it('preserves Phase 5A1 qualification validation during reassignment approval', function () {
    [, $reassignment, $vehicle, $driver] = p5c3Reassignment(false);
    $this->actingAs(p5c3User('dispatcher'))->postJson(route('dispatch.reassignments.approve', $reassignment), [
        'new_vehicle_id' => $vehicle->id, 'new_driver_id' => $driver->id,
    ])->assertUnprocessable();
    expect($reassignment->fresh()->status)->toBe('Requested');
});

it('does not let generic user updates bypass :status permission', function (string $status) {
    config([
        'fleet_rbac.modules.settings.dispatcher' => 'full',
        'fleet_rbac.permissions.dispatcher' => ['users.view', 'users.update'],
    ]);
    $operator = p5c3User('dispatcher');
    $target = p5c3User('finance', ['status' => $status === 'active' ? 'inactive' : 'active']);
    $this->actingAs($operator)->patchJson(route('settings.accounts.update', $target),
        p5c3AccountPayload($target, ['status' => $status]))->assertForbidden();
})->with(['active', 'inactive']);

it('denies operational role :role access to user lifecycle actions', function (string $role) {
    $target = p5c3User('finance');
    $this->actingAs(p5c3User($role))->deleteJson(route('settings.accounts.destroy', $target))
        ->assertForbidden();
})->with(['fleet_manager', 'dispatcher', 'maintenance', 'finance']);

it('preserves self-deactivation and last active IT administrator protections', function () {
    $admin = p5c3User('it_admin');
    $this->actingAs($admin)->deleteJson(route('settings.accounts.destroy', $admin))->assertUnprocessable();
    $this->actingAs($admin)->patchJson(route('settings.accounts.update', $admin),
        p5c3AccountPayload($admin, ['role' => 'finance']))->assertUnprocessable();
    expect($admin->fresh()->status)->toBe('active')->and($admin->fresh()->role)->toBe('it_admin');
});

it('preserves role assignment authorization', function () {
    config([
        'fleet_rbac.modules.settings.dispatcher' => 'full',
        'fleet_rbac.permissions.dispatcher' => ['users.view', 'users.update'],
    ]);
    $target = p5c3User('finance');
    $this->actingAs(p5c3User('dispatcher'))->patchJson(route('settings.accounts.update', $target),
        p5c3AccountPayload($target, ['role' => 'fleet_manager']))->assertForbidden();
    expect($target->fresh()->role)->toBe('finance');
});

it('preserves driver-role safeguards', function () {
    $admin = p5c3User('it_admin');
    $normalUser = p5c3User('finance');
    $driver = p5c3User('driver');
    $this->actingAs($admin)->patchJson(route('settings.accounts.update', $normalUser),
        p5c3AccountPayload($normalUser, ['role' => 'driver']))->assertUnprocessable();
    $this->actingAs($admin)->patchJson(route('settings.accounts.update', $driver), [
        'middle_name' => $driver->middle_name, 'email' => $driver->email, 'role' => 'finance',
        'department' => $driver->department, 'job_title' => $driver->job_title, 'status' => 'active',
    ])->assertSuccessful();
    expect($normalUser->fresh()->role)->toBe('finance')->and($driver->fresh()->role)->toBe('driver');
});
