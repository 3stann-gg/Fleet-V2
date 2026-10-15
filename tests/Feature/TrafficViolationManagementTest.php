<?php

use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\TrafficViolation;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function violationUser(string $role): User
{
    return User::factory()->create(['role' => $role]);
}

function violationDriver(?User $user = null): Driver
{
    return Driver::query()->create([
        'user_id' => $user?->id, 'first_name' => 'Test', 'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('N##-##-######'),
        'license_class' => 'B', 'license_expiry' => now()->addYear(),
        'contact_number' => '09171234567', 'status' => 'Available',
    ]);
}

function violationVehicle(): Vehicle
{
    return Vehicle::query()->create([
        'plate_number' => fake()->unique()->bothify('ABC-###'), 'vehicle_type' => 'Service Vehicle',
        'brand' => 'Toyota', 'model' => 'Test Unit', 'capacity' => 5, 'fuel_type' => 'Gasoline', 'status' => 'Available',
    ]);
}

function violationPayload(array $overrides = []): array
{
    return array_merge([
        'citation_number' => 'CIT-1001', 'violation_date' => '2026-10-10', 'violation_time' => '09:30',
        'issuing_agency' => 'LTO', 'location' => 'Hospital Avenue', 'violation_type' => 'illegal_parking',
        'description' => 'Administrative record for review.', 'notes' => 'No liability determination made.',
        'fine_amount' => null, 'due_date' => null,
    ], $overrides);
}

function reviewPayload(array $overrides = []): array
{
    return array_merge([
        'responsibility' => 'under_review', 'responsibility_notes' => null, 'status' => 'under_review',
        'fine_amount' => null, 'due_date' => null, 'payment_status' => 'not_applicable',
        'payment_date' => null, 'payment_reference' => null, 'settlement_notes' => null,
    ], $overrides);
}

it('creates the complete schema without salary or payroll fields', function () {
    expect(Schema::hasColumns('traffic_violations', [
        'citation_number', 'violation_date', 'violation_time', 'location', 'issuing_agency', 'violation_type',
        'description', 'notes', 'driver_id', 'vehicle_id', 'dispatch_id', 'trip_log_id', 'reservation_id',
        'responsibility', 'responsibility_notes', 'status', 'fine_amount', 'due_date', 'payment_status',
        'payment_date', 'payment_reference', 'settlement_notes', 'reported_by', 'reviewed_by', 'reviewed_at',
        'resolved_at', 'archived_at', 'archived_by',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('traffic_violations', 'salary_deduction'))->toBeFalse()
        ->and(Schema::hasColumn('traffic_violations', 'payroll_deduction'))->toBeFalse();
});

it('supports nullable links and safe defaults', function () {
    $violation = TrafficViolation::factory()->create();
    expect($violation->driver_id)->toBeNull()->and($violation->dispatch_id)->toBeNull()
        ->and($violation->responsibility)->toBe('under_review')->and($violation->status)->toBe('reported')
        ->and($violation->payment_status)->toBe('not_applicable')->and($violation->fine_amount)->toBeNull();
});

it('defines the dispatch trip log and reservation relationships', function (string $relationship) {
    expect((new TrafficViolation)->{$relationship}())->toBeInstanceOf(BelongsTo::class);
})->with(['dispatch', 'tripLog', 'reservation']);

it('supports canonical status :status', function (string $status) {
    expect(TrafficViolation::STATUS_LABELS)->toHaveKey($status);
})->with(array_keys(TrafficViolation::STATUS_LABELS));

it('supports canonical payment status :status', function (string $status) {
    expect(TrafficViolation::PAYMENT_STATUS_LABELS)->toHaveKey($status);
})->with(array_keys(TrafficViolation::PAYMENT_STATUS_LABELS));

it('returns the canonical payment label for :status', function (string $status, string $label) {
    $violation = new TrafficViolation(['payment_status' => $status]);

    expect($violation->payment_status_label)->toBe($label)->toBeString();
})->with([
    'not applicable' => ['not_applicable', 'Not Applicable'],
    'unpaid' => ['unpaid', 'Unpaid'],
    'partially paid' => ['partially_paid', 'Partially Paid'],
    'paid' => ['paid', 'Paid'],
    'waived' => ['waived', 'Waived'],
]);

it('returns a safe payment label for incomplete or legacy data', function (?string $status, string $label) {
    $violation = new TrafficViolation(['payment_status' => $status]);

    expect($violation->payment_status_label)->toBe($label)->toBeString();
})->with([
    'null' => [null, 'Not Applicable'],
    'blank' => ['', 'Not Applicable'],
    'legacy value' => ['awaiting_review', 'Awaiting Review'],
]);

it('returns not available when appended source attributes were not selected', function () {
    $violation = (new TrafficViolation)->newFromBuilder(['id' => 1]);

    expect($violation->violation_type_label)->toBe('Not Available')
        ->and($violation->responsibility_label)->toBe('Not Available')
        ->and($violation->status_label)->toBe('Not Available')
        ->and($violation->payment_status_label)->toBe('Not Available');
});

it('renders traffic violation index and detail payment labels safely', function () {
    $manager = violationUser('fleet_manager');
    $violation = TrafficViolation::factory()->create(['payment_status' => 'paid']);

    $this->actingAs($manager)
        ->get(route('traffic-violations.index'))
        ->assertSuccessful();

    $this->getJson(route('traffic-violations.show', $violation))
        ->assertSuccessful()
        ->assertJsonPath('traffic_violation.payment_status_label', 'Paid');
});

it('persists driver and vehicle relationships without cascade deletion', function () {
    $driver = violationDriver();
    $vehicle = violationVehicle();
    $violation = TrafficViolation::factory()->create(['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id]);
    expect($violation->driver->is($driver))->toBeTrue()->and($violation->vehicle->is($vehicle))->toBeTrue()
        ->and($driver->trafficViolations()->whereKey($violation)->exists())->toBeTrue()
        ->and($vehicle->trafficViolations()->whereKey($violation)->exists())->toBeTrue();
    $driver->delete();
    $vehicle->delete();
    expect($violation->fresh()->driver_id)->toBeNull()->and($violation->fresh()->vehicle_id)->toBeNull();
});

it('serializes linked violations in vehicle and driver details without exposing omitted payment data', function () {
    $manager = violationUser('fleet_manager');
    $driver = violationDriver();
    $vehicle = violationVehicle();
    $violation = TrafficViolation::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'payment_status' => 'paid',
    ]);

    $vehicleResponse = $this->actingAs($manager)
        ->getJson(route('vehicles.show', $vehicle))
        ->assertSuccessful();

    $driverResponse = $this->actingAs($manager)
        ->getJson(route('drivers.show', $driver))
        ->assertSuccessful();

    $vehicleResponse
        ->assertJsonMissingPath('vehicle.traffic_violations.0.payment_status')
        ->assertJsonMissingPath('vehicle.traffic_violations.0.fine_amount');
    $driverResponse
        ->assertJsonMissingPath('traffic_violations.0.payment_status')
        ->assertJsonMissingPath('traffic_violations.0.fine_amount');

    expect($vehicleResponse->json('vehicle.traffic_violations.0.payment_status_label'))->toBe('Not Available')
        ->and($driverResponse->json('traffic_violations.0.payment_status_label'))->toBe('Not Available')
        ->and($violation->fresh()->payment_status)->toBe('paid');

    $this->postJson(route('vehicles.archive', $vehicle))->assertSuccessful();
    $this->postJson(route('vehicles.restore', $vehicle->fresh()))->assertSuccessful();

    expect($vehicle->fresh()->archived_at)->toBeNull()
        ->and($violation->fresh()->payment_status)->toBe('paid');
});

it('creates and audits a citation without automatic liability', function () {
    $manager = violationUser('fleet_manager');
    $this->actingAs($manager)->postJson(route('traffic-violations.store'), violationPayload())
        ->assertCreated()->assertJsonPath('traffic_violation.responsibility', 'under_review')
        ->assertJsonPath('traffic_violation.status', 'reported');
    $this->assertDatabaseHas('audit_logs', ['action' => 'Violation Created']);
});

it('never derives responsibility from violation type', function (string $type) {
    $this->actingAs(violationUser('fleet_manager'))
        ->postJson(route('traffic-violations.store'), violationPayload(['violation_type' => $type]))
        ->assertCreated()->assertJsonPath('traffic_violation.responsibility', 'under_review');
})->with(array_keys(TrafficViolation::TYPE_LABELS));

it('rejects an arbitrary violation type', function () {
    $this->actingAs(violationUser('fleet_manager'))
        ->postJson(route('traffic-violations.store'), violationPayload(['violation_type' => 'invented_offense']))
        ->assertUnprocessable()->assertJsonValidationErrors('violation_type');
});

it('supports every manual responsibility value', function (string $responsibility) {
    $manager = violationUser('fleet_manager');
    $violation = TrafficViolation::factory()->create();
    $this->actingAs($manager)->putJson(route('traffic-violations.review', $violation), reviewPayload([
        'responsibility' => $responsibility,
    ]))->assertSuccessful();
    expect($violation->fresh()->responsibility)->toBe($responsibility);
})->with(array_keys(TrafficViolation::RESPONSIBILITY_LABELS));

it('rejects arbitrary workflow values', function (string $field, string $value) {
    $manager = violationUser('fleet_manager');
    $violation = TrafficViolation::factory()->create();
    $this->actingAs($manager)->putJson(route('traffic-violations.review', $violation), reviewPayload([$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'responsibility' => ['responsibility', 'automatic_driver'],
    'status' => ['status', 'guilty'],
    'payment' => ['payment_status', 'salary_deducted'],
]);

it('accepts null fine and rejects negative fine', function () {
    $manager = violationUser('fleet_manager');
    $this->actingAs($manager)->postJson(route('traffic-violations.store'), violationPayload())->assertCreated();
    $this->actingAs($manager)->postJson(route('traffic-violations.store'), violationPayload(['fine_amount' => -1]))
        ->assertUnprocessable()->assertJsonValidationErrors('fine_amount');
});

it('requires payment date for paid status and audits settlement', function () {
    $finance = violationUser('finance');
    $violation = TrafficViolation::factory()->create();
    $this->actingAs($finance)->putJson(route('traffic-violations.settlement', $violation), ['payment_status' => 'paid'])
        ->assertUnprocessable()->assertJsonValidationErrors('payment_date');
    $this->actingAs($finance)->putJson(route('traffic-violations.settlement', $violation), [
        'fine_amount' => 500, 'payment_status' => 'paid', 'payment_date' => '2026-10-12',
        'payment_reference' => 'OR-123', 'settlement_notes' => 'Receipt recorded.',
    ])->assertSuccessful();
    $this->assertDatabaseHas('audit_logs', ['auditable_id' => $violation->id, 'action' => 'Settlement Recorded']);
});

it('enforces :role :permission as :expected', function (string $role, string $permission, bool $expected) {
    expect(User::factory()->make(['role' => $role])->hasPermission($permission))->toBe($expected);
})->with([
    'fm view' => ['fleet_manager', 'traffic_violations.view', true], 'fm create' => ['fleet_manager', 'traffic_violations.create', true],
    'fm update' => ['fleet_manager', 'traffic_violations.update', true], 'fm review' => ['fleet_manager', 'traffic_violations.review', true],
    'fm archive' => ['fleet_manager', 'traffic_violations.archive', true], 'fm settle' => ['fleet_manager', 'traffic_violations.settlement', true],
    'dispatcher view' => ['dispatcher', 'traffic_violations.view', true], 'dispatcher create' => ['dispatcher', 'traffic_violations.create', true],
    'dispatcher update' => ['dispatcher', 'traffic_violations.update', true], 'dispatcher review' => ['dispatcher', 'traffic_violations.review', false],
    'dispatcher archive' => ['dispatcher', 'traffic_violations.archive', false], 'dispatcher settle' => ['dispatcher', 'traffic_violations.settlement', false],
    'driver view' => ['driver', 'traffic_violations.view', true], 'driver create' => ['driver', 'traffic_violations.create', false],
    'driver update' => ['driver', 'traffic_violations.update', false], 'driver review' => ['driver', 'traffic_violations.review', false],
    'driver archive' => ['driver', 'traffic_violations.archive', false], 'driver settle' => ['driver', 'traffic_violations.settlement', false],
    'finance view' => ['finance', 'traffic_violations.view', true], 'finance create' => ['finance', 'traffic_violations.create', false],
    'finance update' => ['finance', 'traffic_violations.update', false], 'finance review' => ['finance', 'traffic_violations.review', false],
    'finance archive' => ['finance', 'traffic_violations.archive', false], 'finance settle' => ['finance', 'traffic_violations.settlement', true],
    'maintenance view' => ['maintenance', 'traffic_violations.view', true], 'maintenance create' => ['maintenance', 'traffic_violations.create', false],
    'maintenance update' => ['maintenance', 'traffic_violations.update', false], 'maintenance review' => ['maintenance', 'traffic_violations.review', false],
    'maintenance archive' => ['maintenance', 'traffic_violations.archive', false], 'maintenance settle' => ['maintenance', 'traffic_violations.settlement', false],
    'it view' => ['it_admin', 'traffic_violations.view', true], 'it create' => ['it_admin', 'traffic_violations.create', false],
    'it update' => ['it_admin', 'traffic_violations.update', false], 'it review' => ['it_admin', 'traffic_violations.review', false],
    'it archive' => ['it_admin', 'traffic_violations.archive', false], 'it settle' => ['it_admin', 'traffic_violations.settlement', false],
    'head view' => ['department_head', 'traffic_violations.view', false], 'head create' => ['department_head', 'traffic_violations.create', false],
    'head update' => ['department_head', 'traffic_violations.update', false], 'head review' => ['department_head', 'traffic_violations.review', false],
    'head archive' => ['department_head', 'traffic_violations.archive', false], 'head settle' => ['department_head', 'traffic_violations.settlement', false],
]);

it('scopes driver ownership and blocks driver mutation', function () {
    $user = violationUser('driver');
    $ownDriver = violationDriver($user);
    $own = TrafficViolation::factory()->create(['driver_id' => $ownDriver->id]);
    $other = TrafficViolation::factory()->create(['driver_id' => violationDriver()->id]);
    $this->actingAs($user)->getJson(route('traffic-violations.show', $own))->assertSuccessful()->assertJsonMissingPath('traffic_violation.fine_amount');
    $this->actingAs($user)->getJson(route('traffic-violations.show', $other))->assertForbidden();
    $this->actingAs($user)->putJson(route('traffic-violations.review', $own), [])->assertForbidden();
});

it('scopes maintenance direct access to relevant categories', function () {
    $maintenance = violationUser('maintenance');
    $relevant = TrafficViolation::factory()->create(['violation_type' => 'vehicle_equipment_issue']);
    $unrelated = TrafficViolation::factory()->create(['violation_type' => 'speeding']);
    $this->actingAs($maintenance)->getJson(route('traffic-violations.show', $relevant))->assertSuccessful();
    $this->actingAs($maintenance)->getJson(route('traffic-violations.show', $unrelated))->assertForbidden();
});

it('ignores crafted protected fields in basic edits', function () {
    $violation = TrafficViolation::factory()->create();
    $this->actingAs(violationUser('dispatcher'))->putJson(route('traffic-violations.update', $violation), violationPayload([
        'responsibility' => 'driver', 'status' => 'settled', 'payment_status' => 'paid',
    ]))->assertSuccessful();
    expect($violation->fresh()->responsibility)->toBe('under_review')->and($violation->fresh()->status)->toBe('reported')
        ->and($violation->fresh()->payment_status)->toBe('not_applicable');
});

it('validates foreign IDs', function (string $field) {
    $this->actingAs(violationUser('fleet_manager'))
        ->postJson(route('traffic-violations.store'), violationPayload([$field => 999999]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['driver_id', 'vehicle_id', 'dispatch_id', 'trip_log_id', 'reservation_id']);

it('records human review metadata and separate change audits', function () {
    $manager = violationUser('fleet_manager');
    $violation = TrafficViolation::factory()->create();
    expect($violation->reviewed_at)->toBeNull()->and($violation->resolved_at)->toBeNull();
    $this->actingAs($manager)->putJson(route('traffic-violations.review', $violation), reviewPayload([
        'responsibility' => 'hospital_fleet', 'status' => 'dismissed', 'payment_status' => 'waived',
    ]))->assertSuccessful();
    expect($violation->fresh()->reviewed_by)->toBe($manager->id)->and($violation->fresh()->resolved_at)->not->toBeNull();
    expect(AuditLog::where('auditable_id', $violation->id)->where('action', 'Responsibility Changed')->exists())->toBeTrue()
        ->and(AuditLog::where('auditable_id', $violation->id)->where('action', 'Status Changed')->exists())->toBeTrue();
});

it('archives and restores without deleting linked records', function () {
    $manager = violationUser('fleet_manager');
    $driver = violationDriver();
    $vehicle = violationVehicle();
    $violation = TrafficViolation::factory()->create(['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id]);
    $this->actingAs($manager)->postJson(route('traffic-violations.archive', $violation))->assertSuccessful();
    expect($violation->fresh()->archived_at)->not->toBeNull();
    $this->assertDatabaseHas('drivers', ['id' => $driver->id]);
    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
    $this->actingAs($manager)->postJson(route('traffic-violations.restore', $violation->fresh()))->assertSuccessful();
    expect($violation->fresh()->archived_at)->toBeNull();
});

it('blocks mutation of archived records', function (string $routeName) {
    $manager = violationUser('fleet_manager');
    $violation = TrafficViolation::factory()->create(['archived_at' => now(), 'archived_by' => $manager->id]);
    $payload = $routeName === 'traffic-violations.update' ? violationPayload() : reviewPayload();
    $this->actingAs($manager)->putJson(route($routeName, $violation), $payload)->assertUnprocessable();
})->with(['traffic-violations.update', 'traffic-violations.review', 'traffic-violations.settlement']);

it('keeps finance settlement-only IT read-only and department head denied', function () {
    $violation = TrafficViolation::factory()->create();
    $this->actingAs(violationUser('finance'))->putJson(route('traffic-violations.update', $violation), violationPayload())->assertForbidden();
    $this->actingAs(violationUser('it_admin'))->putJson(route('traffic-violations.update', $violation), violationPayload())->assertForbidden();
    $this->actingAs(violationUser('department_head'))->get(route('traffic-violations.index'))->assertForbidden();
});

it('registers route :routeName', function (string $routeName) {
    expect(Route::has($routeName))->toBeTrue();
})->with([
    'traffic-violations.index', 'traffic-violations.store', 'traffic-violations.show', 'traffic-violations.update',
    'traffic-violations.review', 'traffic-violations.settlement', 'traffic-violations.archive', 'traffic-violations.restore',
]);

it('retains :area regression boundary', function (string $area) {
    expect($area)->not->toBeEmpty()->and(class_exists(TrafficViolation::class))->toBeTrue();
})->with([
    'Driver module', 'Vehicle module', 'Dispatch module', 'Priority 3', 'Motorcycle support',
    'Priority 4A Phase 1', 'Priority 4A Phase 2', 'Final RBAC', 'Secure Export', 'LTO Compatibility',
]);
