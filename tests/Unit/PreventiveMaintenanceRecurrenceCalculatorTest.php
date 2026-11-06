<?php

use App\Services\PreventiveMaintenanceRecurrenceCalculator;
use Carbon\CarbonImmutable;

it('calculates weekly recurrence across calendar boundaries', function (string $completion, string $expected) {
    $result = (new PreventiveMaintenanceRecurrenceCalculator)->calculateNextDueDate(
        CarbonImmutable::parse($completion, 'Asia/Manila'),
        PreventiveMaintenanceRecurrenceCalculator::FREQUENCY_WEEKLY,
    );

    expect($result)
        ->toBeInstanceOf(CarbonImmutable::class)
        ->and($result->toDateString())->toBe($expected)
        ->and($result->format('H:i:s'))->toBe('00:00:00')
        ->and($result->getTimezone()->getName())->toBe('Asia/Manila');
})->with([
    'normal week' => ['2026-11-06 14:35:20', '2026-11-13'],
    'month boundary' => ['2026-11-27 23:59:59', '2026-12-04'],
    'year boundary' => ['2026-12-28 08:15:00', '2027-01-04'],
    'leap-year boundary' => ['2028-02-23 12:00:00', '2028-03-01'],
]);

it('preserves weekly timezone and input immutability across a daylight-saving boundary', function () {
    $calculator = new PreventiveMaintenanceRecurrenceCalculator;
    $completionDate = CarbonImmutable::parse('2026-03-07 18:45:30', 'America/New_York');
    $original = $completionDate->format('Y-m-d H:i:s e');

    $first = $calculator->calculateNextDueDate(
        $completionDate,
        PreventiveMaintenanceRecurrenceCalculator::FREQUENCY_WEEKLY,
    );
    $second = $calculator->calculateNextDueDate(
        $completionDate,
        PreventiveMaintenanceRecurrenceCalculator::FREQUENCY_WEEKLY,
    );

    expect($first->toDateString())->toBe('2026-03-14')
        ->and($first->format('H:i:s'))->toBe('00:00:00')
        ->and($first->getTimezone()->getName())->toBe('America/New_York')
        ->and($completionDate->format('Y-m-d H:i:s e'))->toBe($original)
        ->and($second->equalTo($first))->toBeTrue();
});

it('calculates ordinary monthly recurrence without overflow', function (string $completion, string $expected) {
    $result = (new PreventiveMaintenanceRecurrenceCalculator)->calculateNextDueDate(
        CarbonImmutable::parse($completion, 'Asia/Manila'),
        PreventiveMaintenanceRecurrenceCalculator::FREQUENCY_MONTHLY,
    );

    expect($result->toDateString())->toBe($expected)
        ->and($result->format('H:i:s'))->toBe('00:00:00')
        ->and($result->getTimezone()->getName())->toBe('Asia/Manila');
})->with([
    'ordinary month' => ['2026-11-06 16:20:45', '2026-12-06'],
    'year transition' => ['2026-12-15 09:10:11', '2027-01-15'],
    'non-leap February clamp' => ['2027-01-31 22:00:00', '2027-02-28'],
    'leap February clamp' => ['2028-01-31 22:00:00', '2028-02-29'],
    'February twenty-eight remains the twenty-eighth' => ['2028-02-28 22:00:00', '2028-03-28'],
    'thirty-one clamps to a thirty-day month' => ['2027-03-31 22:00:00', '2027-04-30'],
]);

it('handles constrained monthly dates and explicit month-end preservation', function (
    string $completion,
    bool $preserveMonthEnd,
    string $expected,
) {
    $result = (new PreventiveMaintenanceRecurrenceCalculator)->calculateNextDueDate(
        CarbonImmutable::parse($completion, 'UTC'),
        PreventiveMaintenanceRecurrenceCalculator::FREQUENCY_MONTHLY,
        preserveMonthEnd: $preserveMonthEnd,
    );

    expect($result->toDateString())->toBe($expected)
        ->and($result->format('H:i:s'))->toBe('00:00:00')
        ->and($result->getTimezone()->getName())->toBe('UTC');
})->with([
    'non-leap January 28' => ['2027-01-28 13:14:15', false, '2027-02-28'],
    'non-leap January 29' => ['2027-01-29 13:14:15', false, '2027-02-28'],
    'non-leap January 30' => ['2027-01-30 13:14:15', false, '2027-02-28'],
    'non-leap January 31' => ['2027-01-31 13:14:15', false, '2027-02-28'],
    'leap January 28' => ['2028-01-28 13:14:15', false, '2028-02-28'],
    'leap January 29' => ['2028-01-29 13:14:15', false, '2028-02-29'],
    'leap January 30' => ['2028-01-30 13:14:15', false, '2028-02-29'],
    'leap January 31' => ['2028-01-31 13:14:15', false, '2028-02-29'],
    'March 31 no overflow' => ['2027-03-31 13:14:15', false, '2027-04-30'],
    'April month end preserved' => ['2027-04-30 13:14:15', true, '2027-05-31'],
    'leap February 28 explicitly preserved' => ['2028-02-28 13:14:15', true, '2028-03-31'],
    'leap February 29 preserved' => ['2028-02-29 13:14:15', true, '2028-03-31'],
    'December month end preserved' => ['2027-12-31 13:14:15', true, '2028-01-31'],
]);

it('preserves monthly timezone and input immutability while normalizing month end to midnight', function () {
    $calculator = new PreventiveMaintenanceRecurrenceCalculator;
    $completionDate = CarbonImmutable::parse('2028-02-28 21:22:23', 'Pacific/Auckland');
    $original = $completionDate->format('Y-m-d H:i:s e');

    $first = $calculator->calculateNextDueDate(
        $completionDate,
        PreventiveMaintenanceRecurrenceCalculator::FREQUENCY_MONTHLY,
        preserveMonthEnd: true,
    );
    $second = $calculator->calculateNextDueDate(
        $completionDate,
        PreventiveMaintenanceRecurrenceCalculator::FREQUENCY_MONTHLY,
        preserveMonthEnd: true,
    );

    expect($first->toDateString())->toBe('2028-03-31')
        ->and($first->format('H:i:s'))->toBe('00:00:00')
        ->and($first->getTimezone()->getName())->toBe('Pacific/Auckland')
        ->and($completionDate->format('Y-m-d H:i:s e'))->toBe($original)
        ->and($second->equalTo($first))->toBeTrue();
});

it('rejects unsupported recurrence frequencies', function (string $frequency) {
    $calculator = new PreventiveMaintenanceRecurrenceCalculator;

    expect(fn () => $calculator->calculateNextDueDate(
        CarbonImmutable::parse('2026-11-06', 'UTC'),
        $frequency,
    ))->toThrow(
        InvalidArgumentException::class,
        'The preventive maintenance recurrence frequency must be weekly or monthly.',
    );
})->with([
    'empty' => '',
    'daily' => 'daily',
    'yearly' => 'yearly',
    'weekly capitalization' => 'Weekly',
    'monthly capitalization' => 'MONTHLY',
    'arbitrary' => 'quarterly',
]);

it('rejects recurrence intervals other than one', function (int $interval) {
    $calculator = new PreventiveMaintenanceRecurrenceCalculator;

    expect(fn () => $calculator->calculateNextDueDate(
        CarbonImmutable::parse('2026-11-06', 'UTC'),
        PreventiveMaintenanceRecurrenceCalculator::FREQUENCY_WEEKLY,
        $interval,
    ))->toThrow(
        InvalidArgumentException::class,
        'The preventive maintenance recurrence interval must be exactly 1.',
    );
})->with([
    'zero' => 0,
    'negative' => -1,
    'two' => 2,
    'larger interval' => 12,
]);

it('rejects month-end preservation for weekly recurrence', function () {
    $calculator = new PreventiveMaintenanceRecurrenceCalculator;

    expect(fn () => $calculator->calculateNextDueDate(
        CarbonImmutable::parse('2026-11-06', 'UTC'),
        PreventiveMaintenanceRecurrenceCalculator::FREQUENCY_WEEKLY,
        preserveMonthEnd: true,
    ))->toThrow(
        InvalidArgumentException::class,
        'Month-end preservation is only supported for monthly recurrence.',
    );
});
