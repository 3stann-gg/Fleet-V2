<?php

declare(strict_types=1);

use App\Services\RouteDepartureContextService;
use Carbon\CarbonImmutable;

it('resolves authoritative future reservation schedules without shifting the local calendar', function (string $date, string $time, string $expected, string $utc): void {
    $clock = CarbonImmutable::parse('2026-11-01T00:00:00Z');
    $service = new RouteDepartureContextService(fn (): CarbonImmutable => $clock);

    $result = $service->forPlannedDeparture($date, $time);

    expect($result)->toBe([
        'mode' => 'planned',
        'depart_at' => $expected,
        'timezone' => 'Asia/Manila',
        'source' => 'reservation_schedule',
        'verification' => 'requested_context_only',
    ])->and(CarbonImmutable::parse($result['depart_at'])->utc()->format('Y-m-d\TH:i:s\Z'))->toBe($utc)
        ->and($clock->format('Y-m-d\TH:i:sP'))->toBe('2026-11-01T00:00:00+00:00');
})->with([
    'minute precision' => ['2026-11-20', '09:00', '2026-11-20T09:00:00+08:00', '2026-11-20T01:00:00Z'],
    'stored second precision' => ['2026-11-20', '09:30:45', '2026-11-20T09:30:45+08:00', '2026-11-20T01:30:45Z'],
    'explicit midnight' => ['2026-11-20', '00:00', '2026-11-20T00:00:00+08:00', '2026-11-19T16:00:00Z'],
    'year boundary' => ['2027-01-01', '00:00:01', '2027-01-01T00:00:01+08:00', '2026-12-31T16:00:01Z'],
    'valid leap day' => ['2028-02-29', '23:59:59', '2028-02-29T23:59:59+08:00', '2028-02-29T15:59:59Z'],
    'century leap day' => ['2400-02-29', '12:00:00', '2400-02-29T12:00:00+08:00', '2400-02-29T04:00:00Z'],
]);

it('rejects incomplete malformed or inconsistent schedules without using the clock as a default', function (?string $date, ?string $time): void {
    $calls = 0;
    $service = new RouteDepartureContextService(function () use (&$calls): CarbonImmutable {
        $calls++;

        return CarbonImmutable::parse('2026-11-01T00:00:00Z');
    });

    expect(fn () => $service->forPlannedDeparture($date, $time))->toThrow(InvalidArgumentException::class)
        ->and($calls)->toBe(0);
})->with([
    'missing date' => [null, '09:00'],
    'missing time' => ['2026-11-20', null],
    'both missing' => [null, null],
    'empty date' => ['', '09:00'],
    'empty time' => ['2026-11-20', ''],
    'partial date' => ['2026-11', '09:00'],
    'partial time' => ['2026-11-20', '09'],
    'partial seconds' => ['2026-11-20', '09:00:'],
    'nonpadded date' => ['2026-1-2', '09:00'],
    'nonpadded hour' => ['2026-11-20', '9:00'],
    'alternate date format' => ['11/20/2026', '09:00'],
    'natural language date' => ['tomorrow', '09:00'],
    'natural language time' => ['2026-11-20', 'noon'],
    'date includes time' => ['2026-11-20T09:00:00', '09:00'],
    'time includes browser offset' => ['2026-11-20', '09:00:00-05:00'],
    'time includes UTC marker' => ['2026-11-20', '09:00Z'],
    'fractional seconds' => ['2026-11-20', '09:00:00.123'],
    'trailing date newline' => ["2026-11-20\n", '09:00'],
    'trailing time newline' => ['2026-11-20', "09:00\n"],
    'leading whitespace' => [' 2026-11-20', '09:00'],
    'trailing whitespace' => ['2026-11-20', '09:00 '],
    'null byte' => ["2026-11-20\0", '09:00'],
    'nonleap February' => ['2027-02-29', '09:00'],
    'invalid leap February' => ['2028-02-30', '09:00'],
    'nonleap century' => ['2100-02-29', '09:00'],
    'April overflow' => ['2027-04-31', '09:00'],
    'month overflow' => ['2026-13-01', '09:00'],
    'zero month' => ['2026-00-01', '09:00'],
    'zero day' => ['2026-11-00', '09:00'],
    'zero year' => ['0000-11-20', '09:00'],
    '24 hour overflow' => ['2026-11-20', '24:00'],
    'minute overflow' => ['2026-11-20', '09:60'],
    'second overflow' => ['2026-11-20', '09:00:60'],
    'AM PM time' => ['2026-11-20', '09:00 AM'],
]);

it('rejects every past planned instant without switching to current navigation', function (string $clock, string $date, string $time): void {
    $calls = 0;
    $service = new RouteDepartureContextService(function () use ($clock, &$calls): CarbonImmutable {
        $calls++;

        return CarbonImmutable::parse($clock);
    });

    expect(fn () => $service->forPlannedDeparture($date, $time))
        ->toThrow(InvalidArgumentException::class, 'The planned departure must not be in the past.')
        ->and($calls)->toBe(1);
})->with([
    'previous second' => ['2026-11-20T01:00:01Z', '2026-11-20', '09:00:00'],
    'subsecond boundary is not rounded away' => ['2026-11-20T01:00:00.000001Z', '2026-11-20', '09:00'],
    'UTC day differs from Manila day' => ['2026-11-19T16:00:00Z', '2026-11-19', '23:59:59'],
]);

it('accepts exactly equal planned instants and preserves the planned context', function (string $time): void {
    $service = new RouteDepartureContextService(fn (): CarbonImmutable => CarbonImmutable::parse('2026-11-20T01:00:00Z'));

    expect($service->forPlannedDeparture('2026-11-20', $time))->toBe([
        'mode' => 'planned',
        'depart_at' => '2026-11-20T09:00:00+08:00',
        'timezone' => 'Asia/Manila',
        'source' => 'reservation_schedule',
        'verification' => 'requested_context_only',
    ]);
})->with(['09:00', '09:00:00']);

it('captures one server clock instant for an explicit current navigation context', function (): void {
    $calls = 0;
    $service = new RouteDepartureContextService(function () use (&$calls): CarbonImmutable {
        $calls++;

        return CarbonImmutable::parse('2026-12-31T16:00:01Z');
    });

    expect($service->forCurrentNavigation())->toBe([
        'mode' => 'current',
        'depart_at' => '2027-01-01T00:00:01+08:00',
        'timezone' => 'Asia/Manila',
        'source' => 'server_clock',
        'verification' => 'requested_context_only',
    ])->and($calls)->toBe(1);
});

it('is independent of server and caller timezone presentation', function (string $serverTimezone, string $clockTimezone): void {
    $originalTimezone = date_default_timezone_get();
    $clock = CarbonImmutable::parse('2026-11-01T00:00:00Z')->setTimezone($clockTimezone);

    try {
        date_default_timezone_set($serverTimezone);
        $service = new RouteDepartureContextService(fn (): CarbonImmutable => $clock);

        expect($service->forPlannedDeparture('2026-11-20', '09:00')['depart_at'])->toBe('2026-11-20T09:00:00+08:00')
            ->and($service->forCurrentNavigation()['depart_at'])->toBe('2026-11-01T08:00:00+08:00')
            ->and($clock->getTimezone()->getName())->toBe($clockTimezone);
    } finally {
        date_default_timezone_set($originalTimezone);
    }
})->with([
    ['UTC', 'America/New_York'],
    ['America/Los_Angeles', 'Pacific/Auckland'],
    ['Europe/London', 'Asia/Tokyo'],
]);

it('copies mutable clock values and returns independent scalar context snapshots', function (): void {
    $clock = new DateTime('2026-11-20T01:00:00+00:00');
    $before = $clock->format('Y-m-d H:i:s.u e');
    $service = new RouteDepartureContextService(fn (): DateTime => $clock);
    $first = $service->forCurrentNavigation();
    $planned = $service->forPlannedDeparture('2026-11-20', '09:00');

    expect($clock->format('Y-m-d H:i:s.u e'))->toBe($before);

    $first['depart_at'] = 'Changed outside the service';
    $clock->modify('+1 second');

    expect($planned['depart_at'])->toBe('2026-11-20T09:00:00+08:00')
        ->and($service->forCurrentNavigation()['depart_at'])->toBe('2026-11-20T09:00:01+08:00')
        ->and($clock->getTimezone()->getName())->toBe('+00:00');
});

it('keeps independent resolver clocks separate without caching a shared instant', function (): void {
    $first = new RouteDepartureContextService(fn (): CarbonImmutable => CarbonImmutable::parse('2026-11-20T01:00:00Z'));
    $second = new RouteDepartureContextService(fn (): CarbonImmutable => CarbonImmutable::parse('2026-11-20T02:00:00Z'));

    expect($first->forCurrentNavigation()['depart_at'])->toBe('2026-11-20T09:00:00+08:00')
        ->and($second->forCurrentNavigation()['depart_at'])->toBe('2026-11-20T10:00:00+08:00')
        ->and($first->forCurrentNavigation()['depart_at'])->toBe('2026-11-20T09:00:00+08:00');
});

it('uses the default server clock through a deterministically controlled Carbon clock', function (): void {
    CarbonImmutable::withTestNow(CarbonImmutable::parse('2026-11-20T01:00:00Z'), function (): void {
        $service = new RouteDepartureContextService;

        expect($service->forCurrentNavigation()['depart_at'])->toBe('2026-11-20T09:00:00+08:00')
            ->and($service->forPlannedDeparture('2026-11-20', '09:00')['mode'])->toBe('planned');
    });
});

it('fails closed when an injected clock does not return a date-time object', function (mixed $value): void {
    $service = new RouteDepartureContextService(fn (): mixed => $value);

    expect(fn () => $service->forCurrentNavigation())->toThrow(UnexpectedValueException::class)
        ->and(fn () => $service->forPlannedDeparture('2026-11-20', '09:00'))->toThrow(UnexpectedValueException::class);
})->with([null, '2026-11-20T01:00:00Z', false]);
