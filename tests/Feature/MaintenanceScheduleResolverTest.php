<?php

use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\MaintenanceScheduleResolver;

function maintenanceScheduleResolverVehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('MSR-####'),
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'Schedule',
        'model' => 'Resolver',
        'capacity' => 4,
        'fuel_type' => 'Gasoline',
        'status' => 'Available',
    ], $attributes));
}

function maintenanceScheduleResolverRecord(Vehicle $vehicle, array $attributes = []): Maintenance
{
    return Maintenance::query()->create(array_merge([
        'maintenance_number' => fake()->unique()->bothify('MSR-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Resolver test maintenance',
        'maintenance_date' => now()->subMonth()->toDateString(),
        'completion_date' => now()->subMonth()->toDateString(),
        'next_schedule' => now()->addMonth()->toDateString(),
        'cost' => 0,
        'status' => 'Completed',
    ], $attributes));
}

it('returns an empty context when the vehicle has no maintenance history', function () {
    $vehicle = maintenanceScheduleResolverVehicle();

    expect(app(MaintenanceScheduleResolver::class)->resolve($vehicle))->toMatchArray([
        'vehicle_id' => $vehicle->id,
        'maintenance' => null,
        'maintenance_id' => null,
        'completion_date' => null,
        'next_schedule' => null,
        'has_next_schedule' => false,
    ]);
});

it('resolves one completed record and its future next schedule', function () {
    $vehicle = maintenanceScheduleResolverVehicle();
    $nextSchedule = now()->addMonths(2)->toDateString();
    $maintenance = maintenanceScheduleResolverRecord($vehicle, [
        'next_schedule' => $nextSchedule,
    ]);
    $context = app(MaintenanceScheduleResolver::class)->resolve($vehicle);

    expect($context['maintenance'])->toBeInstanceOf(Maintenance::class)
        ->and($context['maintenance_id'])->toBe($maintenance->id)
        ->and($context['next_schedule'])->toBe($nextSchedule)
        ->and($context['has_next_schedule'])->toBeTrue();
});

it('allows the latest completed record to supersede an older completed record', function () {
    $vehicle = maintenanceScheduleResolverVehicle();
    maintenanceScheduleResolverRecord($vehicle, [
        'completion_date' => now()->subMonths(2)->toDateString(),
        'next_schedule' => now()->addWeek()->toDateString(),
    ]);
    $latest = maintenanceScheduleResolverRecord($vehicle, [
        'completion_date' => now()->subMonth()->toDateString(),
        'next_schedule' => now()->addMonths(3)->toDateString(),
    ]);

    expect(app(MaintenanceScheduleResolver::class)->resolve($vehicle)['maintenance_id'])
        ->toBe($latest->id);
});

it('uses the highest id to break equal completion date ties', function () {
    $vehicle = maintenanceScheduleResolverVehicle();
    $completionDate = now()->subMonth()->toDateString();
    maintenanceScheduleResolverRecord($vehicle, ['completion_date' => $completionDate]);
    $higherId = maintenanceScheduleResolverRecord($vehicle, ['completion_date' => $completionDate]);

    expect(app(MaintenanceScheduleResolver::class)->resolve($vehicle)['maintenance_id'])
        ->toBe($higherId->id);
});

it('does not revive an older schedule when the latest completed record has no next schedule', function () {
    $vehicle = maintenanceScheduleResolverVehicle();
    maintenanceScheduleResolverRecord($vehicle, [
        'completion_date' => now()->subMonths(2)->toDateString(),
        'next_schedule' => now()->addWeek()->toDateString(),
    ]);
    $latest = maintenanceScheduleResolverRecord($vehicle, [
        'completion_date' => now()->subMonth()->toDateString(),
        'next_schedule' => null,
    ]);
    $context = app(MaintenanceScheduleResolver::class)->resolve($vehicle);

    expect($context['maintenance_id'])->toBe($latest->id)
        ->and($context['next_schedule'])->toBeNull()
        ->and($context['has_next_schedule'])->toBeFalse();
});

it('ignores scheduled in progress and cancelled records as authoritative sources', function (string $status) {
    $vehicle = maintenanceScheduleResolverVehicle();
    $completed = maintenanceScheduleResolverRecord($vehicle);
    maintenanceScheduleResolverRecord($vehicle, [
        'status' => $status,
        'completion_date' => now()->toDateString(),
        'maintenance_date' => now()->toDateString(),
    ]);

    expect(app(MaintenanceScheduleResolver::class)->resolve($vehicle)['maintenance_id'])
        ->toBe($completed->id);
})->with(['Scheduled', 'In Progress', 'Cancelled']);

it('preserves the existing behavior that archived completed records remain eligible', function () {
    $vehicle = maintenanceScheduleResolverVehicle();
    maintenanceScheduleResolverRecord($vehicle, [
        'completion_date' => now()->subMonths(2)->toDateString(),
    ]);
    $archived = maintenanceScheduleResolverRecord($vehicle, [
        'completion_date' => now()->subMonth()->toDateString(),
        'archived_at' => now(),
    ]);

    expect(app(MaintenanceScheduleResolver::class)->resolve($vehicle)['maintenance_id'])
        ->toBe($archived->id);
});

it('reproduces the relationship behavior for historical completed records with null completion dates', function () {
    $vehicle = maintenanceScheduleResolverVehicle();
    maintenanceScheduleResolverRecord($vehicle, [
        'completion_date' => null,
    ]);

    expect(app(MaintenanceScheduleResolver::class)->resolve($vehicle)['maintenance'])
        ->toBeNull();

    $datedCompletion = maintenanceScheduleResolverRecord($vehicle, [
        'completion_date' => now()->subYear()->toDateString(),
    ]);

    expect(app(MaintenanceScheduleResolver::class)->resolve($vehicle)['maintenance_id'])
        ->toBe($datedCompletion->id);
});

it('does not modify vehicle or maintenance database state', function () {
    $vehicle = maintenanceScheduleResolverVehicle();
    $maintenance = maintenanceScheduleResolverRecord($vehicle);
    $vehicleAttributes = $vehicle->fresh()->getRawOriginal();
    $maintenanceAttributes = $maintenance->fresh()->getRawOriginal();

    app(MaintenanceScheduleResolver::class)->resolve($vehicle);

    expect($vehicle->fresh()->getRawOriginal())->toBe($vehicleAttributes)
        ->and($maintenance->fresh()->getRawOriginal())->toBe($maintenanceAttributes)
        ->and(Maintenance::query()->count())->toBe(1);
});

it('resolves stably and remains compatible with the loaded vehicle relationship', function () {
    $vehicle = maintenanceScheduleResolverVehicle();
    $maintenance = maintenanceScheduleResolverRecord($vehicle);
    $vehicle->load('lastCompletedMaintenance');
    $resolver = app(MaintenanceScheduleResolver::class);
    $first = $resolver->resolve($vehicle);
    $second = $resolver->resolve($vehicle);

    expect($vehicle->lastCompletedMaintenance?->id)->toBe($maintenance->id)
        ->and($first['maintenance_id'])->toBe($maintenance->id)
        ->and($second)->toEqual($first);
});
