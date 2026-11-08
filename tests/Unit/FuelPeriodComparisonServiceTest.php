<?php

use App\Services\FuelPeriodComparisonService;
use Carbon\CarbonImmutable;

function periodRecord(string $date, float $cost = 10, ?float $distance = 100, float $liters = 10): array
{
    return ['date' => $date, 'cost' => $cost, 'distance_km' => $distance, 'fuel_amount' => $liters];
}

it('constructs complete nonoverlapping calendar periods without mutating the captured date', function (string $day, string $previousStart, string $currentStart, string $currentEnd) {
    $asOf = CarbonImmutable::parse($day.' 14:23:00', 'Asia/Manila');
    $result = (new FuelPeriodComparisonService)->compare(collect(), $asOf);

    expect($result['previous']['start_date'])->toBe($previousStart)
        ->and($result['previous']['end_date'])->toBe($asOf->subDays(31)->toDateString())
        ->and($result['current']['start_date'])->toBe($currentStart)
        ->and($result['current']['end_date'])->toBe($currentEnd)
        ->and($result['timezone'])->toBe('Asia/Manila')
        ->and($asOf->format('H:i:s'))->toBe('14:23:00');
})->with([
    ['2026-11-08', '2026-09-09', '2026-10-09', '2026-11-07'],
    ['2027-01-15', '2026-11-16', '2026-12-16', '2027-01-14'],
    ['2028-03-01', '2028-01-01', '2028-01-31', '2028-02-29'],
    ['2027-03-01', '2026-12-31', '2027-01-30', '2027-02-28'],
]);

it('includes the four boundaries and excludes old records today and future transactions', function () {
    $dates = ['2026-09-08', '2026-09-09', '2026-10-08', '2026-10-09', '2026-11-07', '2026-11-08', '2026-11-09'];
    $result = (new FuelPeriodComparisonService)->compare(collect($dates)->map(fn ($date) => periodRecord($date)), CarbonImmutable::parse('2026-11-08', 'Asia/Manila'));

    expect($result['previous']['record_count'])->toBe(2)
        ->and($result['current']['record_count'])->toBe(2)
        ->and($result['previous']['fuel_cost'])->toEqual(20)
        ->and($result['current']['fuel_cost'])->toEqual(20);
});

it('calculates signed expense changes and handles zero baselines', function (float $previous, float $current, ?float $percent, string $direction) {
    $result = (new FuelPeriodComparisonService)->compare(collect([
        periodRecord('2026-09-15', $previous), periodRecord('2026-10-15', $current),
    ]), CarbonImmutable::parse('2026-11-08', 'Asia/Manila'));

    expect($result['changes']['fuel_cost_percent'])->toBe($percent)
        ->and($result['changes']['cost_direction'])->toBe($direction);
})->with([
    [100, 125, 25.0, 'Increase'], [100, 75, -25.0, 'Decrease'],
    [100, 100, 0.0, 'Stable'], [0, 100, null, 'Unavailable'],
    [100, 0, -100.0, 'Decrease'], [0, 0, null, 'Unavailable'],
]);

it('uses weighted distance and liters and compares unrounded efficiencies', function () {
    $result = (new FuelPeriodComparisonService)->compare(collect([
        periodRecord('2026-09-15', 10, 100, 10),
        periodRecord('2026-09-16', 20, 300, 20),
        periodRecord('2026-10-15', 30, 100, 7),
    ]), CarbonImmutable::parse('2026-11-08', 'Asia/Manila'));

    expect($result['previous']['estimated_efficiency_km_per_liter'])->toBe(13.33)
        ->and($result['current']['estimated_efficiency_km_per_liter'])->toBe(14.29)
        ->and($result['changes']['estimated_efficiency_percent'])->toBe(7.14)
        ->and($result['changes']['efficiency_direction'])->toBe('Improving');
});

it('provides neutral advice only for a supported declining estimate', function () {
    $result = (new FuelPeriodComparisonService)->compare(collect([
        periodRecord('2026-09-15', 100, 200), periodRecord('2026-10-15', 200, 100),
    ]), CarbonImmutable::parse('2026-11-08', 'Asia/Manila'));

    expect($result['changes']['estimated_efficiency_percent'])->toBe(-50.0)
        ->and($result['changes']['efficiency_direction'])->toBe('Declining')
        ->and($result['advisory'])->toContain('Estimated fuel efficiency declined');
});

it('returns unavailable efficiencies while retaining recorded costs for invalid intervals', function (?float $distance, float $liters) {
    $result = (new FuelPeriodComparisonService)->compare(collect([
        periodRecord('2026-09-15', 100, $distance, $liters), periodRecord('2026-10-15', 150),
    ]), CarbonImmutable::parse('2026-11-08', 'Asia/Manila'));

    expect($result['previous']['fuel_cost'])->toEqual(100)
        ->and($result['previous']['estimated_efficiency_km_per_liter'])->toBeNull()
        ->and($result['changes']['estimated_efficiency_percent'])->toBeNull()
        ->and($result['advisory'])->toBeNull();
})->with([[null, 10], [0, 10], [-10, 10], [100, 0], [100, -1]]);

it('distinguishes empty periods from valid zero recorded expense', function () {
    $result = (new FuelPeriodComparisonService)->compare(collect(), CarbonImmutable::parse('2026-11-08', 'Asia/Manila'));

    expect($result['current']['fuel_cost'])->toEqual(0)
        ->and($result['current']['expense_reason'])->toContain('No recorded')
        ->and($result['current']['valid_interval_count'])->toBe(0)
        ->and($result['changes']['fuel_cost_percent'])->toBeNull()
        ->and($result['changes']['estimated_efficiency_percent'])->toBeNull();
});

it('retains the true direction when a tiny percentage rounds to zero', function () {
    $result = (new FuelPeriodComparisonService)->compare(collect([
        periodRecord('2026-09-15', 10000), periodRecord('2026-10-15', 10000.01),
    ]), CarbonImmutable::parse('2026-11-08', 'Asia/Manila'));

    expect($result['changes']['fuel_cost_percent'])->toBe(0.0)
        ->and($result['changes']['cost_direction'])->toBe('Increase')
        ->and($result['changes']['efficiency_direction'])->toBe('Stable');
});
