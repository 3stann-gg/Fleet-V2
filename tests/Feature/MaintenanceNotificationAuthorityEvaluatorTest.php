<?php

use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\MaintenanceNotificationAuthorityEvaluator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function authorityEvaluatorVehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('MAE-####'),
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'Authority',
        'model' => 'Evaluator',
        'capacity' => 4,
        'fuel_type' => 'Gasoline',
        'status' => 'Available',
    ], $attributes));
}

function authorityEvaluatorMaintenance(Vehicle $vehicle, array $attributes = []): Maintenance
{
    return Maintenance::query()->create(array_merge([
        'maintenance_number' => fake()->unique()->bothify('MAE-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Authority evaluator test',
        'maintenance_date' => now()->subMonth()->toDateString(),
        'completion_date' => null,
        'next_schedule' => now()->addDays(3)->toDateString(),
        'cost' => 0,
        'status' => 'Scheduled',
    ], $attributes));
}

function authorityEvaluatorEventKey(
    Maintenance $maintenance,
    ?string $scheduleDate = null
): string {
    return 'maintenance_due:'.$maintenance->id.':'.(
        $scheduleDate ?? $maintenance->next_schedule?->toDateString()
    );
}

function authorityEvaluator(): MaintenanceNotificationAuthorityEvaluator
{
    return app(MaintenanceNotificationAuthorityEvaluator::class);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-30 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('rejects malformed and impossible canonical identities', function (string $eventKey) {
    $evaluation = authorityEvaluator()->evaluateEventKey($eventKey);

    expect($evaluation)->toMatchArray([
        'event_key' => $eventKey,
        'verified' => false,
        'authoritative' => null,
        'resolution_reason' => null,
    ])->and(authorityEvaluator()->isCanonicalEventKey($eventKey))->toBeFalse();
})->with([
    'wrong type' => 'driver_license_expiring:1:2026-12-01',
    'zero id' => 'maintenance_due:0:2026-12-01',
    'impossible date' => 'maintenance_due:1:2026-02-30',
    'extra suffix' => 'maintenance_due:1:2026-12-01:occurrence:2',
]);

it('reports a canonical identity with a missing source conservatively', function () {
    $eventKey = 'maintenance_due:999999:2026-12-01';
    $evaluation = authorityEvaluator()->evaluateEventKey($eventKey);

    expect(authorityEvaluator()->isCanonicalEventKey($eventKey))->toBeTrue()
        ->and($evaluation['verified'])->toBeFalse()
        ->and($evaluation['maintenance_id'])->toBe(999999)
        ->and($evaluation['schedule_date'])->toBe('2026-12-01')
        ->and($evaluation['resolution_reason'])->toBeNull();
});

it('keeps scheduled and in progress matching schedules authoritative', function (string $status) {
    $maintenance = authorityEvaluatorMaintenance(
        authorityEvaluatorVehicle(),
        ['status' => $status]
    );
    $evaluation = authorityEvaluator()->evaluateEventKey(
        authorityEvaluatorEventKey($maintenance)
    );

    expect($evaluation)->toMatchArray([
        'verified' => true,
        'authoritative' => true,
        'maintenance_id' => $maintenance->id,
        'vehicle_id' => $maintenance->vehicle_id,
        'current_schedule_date' => $maintenance->next_schedule->toDateString(),
        'resolution_reason' => null,
    ]);
})->with(['Scheduled', 'In Progress']);

it('identifies changed and cleared schedules', function (string $change, string $reason) {
    $maintenance = authorityEvaluatorMaintenance(authorityEvaluatorVehicle());
    $eventKey = authorityEvaluatorEventKey($maintenance);
    $maintenance->update([
        'next_schedule' => $change === 'cleared'
            ? null
            : now()->addWeek()->toDateString(),
    ]);

    $evaluation = authorityEvaluator()->evaluateEventKey($eventKey);

    expect($evaluation['verified'])->toBeTrue()
        ->and($evaluation['authoritative'])->toBeFalse()
        ->and($evaluation['resolution_reason'])->toBe($reason);
})->with([
    'changed schedule' => ['changed', 'schedule_changed'],
    'cleared schedule' => ['cleared', 'schedule_cleared'],
]);

it('treats cancelled maintenance as inactive', function () {
    $maintenance = authorityEvaluatorMaintenance(authorityEvaluatorVehicle());
    $eventKey = authorityEvaluatorEventKey($maintenance);
    $maintenance->update([
        'status' => 'Cancelled',
        'next_schedule' => null,
    ]);

    expect(authorityEvaluator()->evaluateEventKey($eventKey)['resolution_reason'])
        ->toBe('maintenance_cancelled');
});

it('uses the resolver-selected latest completed maintenance as authority', function () {
    $vehicle = authorityEvaluatorVehicle();
    $older = authorityEvaluatorMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => now()->subMonths(2)->toDateString(),
    ]);
    $latest = authorityEvaluatorMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => now()->subMonth()->toDateString(),
    ]);

    $evaluations = authorityEvaluator()->evaluateEventKeys([
        authorityEvaluatorEventKey($older),
        authorityEvaluatorEventKey($latest),
    ]);

    expect($evaluations[authorityEvaluatorEventKey($older)]['resolution_reason'])
        ->toBe('superseded_by_newer_completion')
        ->and($evaluations[authorityEvaluatorEventKey($latest)]['authoritative'])
        ->toBeTrue();
});

it('uses the highest id for equal completed dates', function () {
    $vehicle = authorityEvaluatorVehicle();
    $completionDate = now()->subMonth()->toDateString();
    $lowerId = authorityEvaluatorMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => $completionDate,
    ]);
    $higherId = authorityEvaluatorMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => $completionDate,
    ]);

    expect(authorityEvaluator()->evaluateEventKey(
        authorityEvaluatorEventKey($lowerId)
    )['resolution_reason'])->toBe('superseded_by_newer_completion')
        ->and(authorityEvaluator()->evaluateEventKey(
            authorityEvaluatorEventKey($higherId)
        )['authoritative'])->toBeTrue();
});

it('does not revive an older schedule when latest completed next schedule is null', function () {
    $vehicle = authorityEvaluatorVehicle();
    $older = authorityEvaluatorMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => now()->subMonths(2)->toDateString(),
    ]);
    $latest = authorityEvaluatorMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => now()->subMonth()->toDateString(),
    ]);
    $latestEventKey = authorityEvaluatorEventKey($latest);
    $latest->update(['next_schedule' => null]);

    expect(authorityEvaluator()->evaluateEventKey(
        authorityEvaluatorEventKey($older)
    )['resolution_reason'])->toBe('superseded_by_newer_completion')
        ->and(authorityEvaluator()->evaluateEventKey(
            $latestEventKey
        )['resolution_reason'])->toBe('schedule_cleared');
});

it('preserves archived completed maintenance authority', function () {
    $maintenance = authorityEvaluatorMaintenance(authorityEvaluatorVehicle(), [
        'status' => 'Completed',
        'completion_date' => now()->subMonth()->toDateString(),
        'archived_at' => now(),
    ]);

    expect(authorityEvaluator()->evaluateEventKey(
        authorityEvaluatorEventKey($maintenance)
    )['authoritative'])->toBeTrue();
});

it('preserves legacy null completion-date behavior', function () {
    $maintenance = authorityEvaluatorMaintenance(authorityEvaluatorVehicle(), [
        'status' => 'Completed',
        'completion_date' => null,
    ]);

    expect(authorityEvaluator()->evaluateEventKey(
        authorityEvaluatorEventKey($maintenance)
    )['resolution_reason'])->toBe('source_no_longer_eligible');
});

it('allows active and completed reminders to remain independently authoritative', function () {
    $vehicle = authorityEvaluatorVehicle();
    $completed = authorityEvaluatorMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => now()->subMonth()->toDateString(),
    ]);
    $active = authorityEvaluatorMaintenance($vehicle, [
        'status' => 'Scheduled',
        'next_schedule' => now()->addDays(2)->toDateString(),
    ]);
    $evaluations = authorityEvaluator()->evaluateEventKeys([
        authorityEvaluatorEventKey($completed),
        authorityEvaluatorEventKey($active),
    ]);

    expect($evaluations[authorityEvaluatorEventKey($completed)]['authoritative'])
        ->toBeTrue()
        ->and($evaluations[authorityEvaluatorEventKey($active)]['authoritative'])
        ->toBeTrue();
});

it('does not mix authority across vehicles', function () {
    $first = authorityEvaluatorMaintenance(authorityEvaluatorVehicle());
    $second = authorityEvaluatorMaintenance(authorityEvaluatorVehicle());
    $firstEventKey = authorityEvaluatorEventKey($first);
    $secondEventKey = authorityEvaluatorEventKey($second);
    $first->update(['next_schedule' => now()->addWeek()->toDateString()]);
    $evaluations = authorityEvaluator()->evaluateEventKeys([
        $firstEventKey,
        $secondEventKey,
    ]);

    expect($evaluations[$firstEventKey]['resolution_reason'])->toBe('schedule_changed')
        ->and($evaluations[$secondEventKey]['authoritative'])->toBeTrue();
});

it('evaluates batches without per-event source queries', function () {
    $first = authorityEvaluatorMaintenance(authorityEvaluatorVehicle());
    $second = authorityEvaluatorMaintenance(authorityEvaluatorVehicle());
    $selectQueries = 0;

    DB::listen(function ($query) use (&$selectQueries): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $selectQueries++;
        }
    });

    authorityEvaluator()->evaluateEventKeys([
        authorityEvaluatorEventKey($first),
        authorityEvaluatorEventKey($second),
    ]);

    expect($selectQueries)->toBeLessThanOrEqual(3);
});

it('performs no writes during repeated evaluation', function () {
    $vehicle = authorityEvaluatorVehicle();
    $maintenance = authorityEvaluatorMaintenance($vehicle);
    $vehicleAttributes = $vehicle->fresh()->getRawOriginal();
    $maintenanceAttributes = $maintenance->fresh()->getRawOriginal();
    $eventKey = authorityEvaluatorEventKey($maintenance);

    $first = authorityEvaluator()->evaluateEventKey($eventKey);
    $second = authorityEvaluator()->evaluateEventKey($eventKey);

    expect($second)->toEqual($first)
        ->and($vehicle->fresh()->getRawOriginal())->toBe($vehicleAttributes)
        ->and($maintenance->fresh()->getRawOriginal())->toBe($maintenanceAttributes);
});
