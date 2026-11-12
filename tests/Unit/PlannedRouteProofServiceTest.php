<?php

declare(strict_types=1);

use App\Http\Controllers\RoutePlanController;
use App\Models\Reservation;
use App\Models\RoutePlan;
use App\Models\User;
use App\Services\PlannedRouteProofService;
use App\Services\RouteDepartureContextService;
use App\Services\TomTomRoutingService;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class);

/** @return array<string, mixed> */
function plannedProofFixture(string $operation = 'update'): array
{
    $instant = CarbonImmutable::parse('2026-11-12T00:00:00Z');
    $encrypter = new Encrypter(str_repeat('s', 32), 'AES-256-CBC');
    $config = new Repository(['services' => ['tomtom' => [
        'planned_departure_enabled' => true, 'planned_proof_issuance_enabled' => true,
        'planned_apply_enabled' => false, 'planned_save_enabled' => false,
    ]]]);
    $service = new PlannedRouteProofService($encrypter, $config, fn (): CarbonImmutable => $instant);
    $context = [
        'user_id' => 17, 'reservation_id' => 8, 'route_id' => 7, 'route_reservation_id' => 8,
        'route_status' => 'Planned', 'intended_operation' => $operation,
        'expected_estimate_revision' => 0, 'target_status' => 'Planned',
        'schedule_date' => '2026-11-20', 'schedule_time' => '09:00:00',
    ];
    $coordinates = [
        ['latitude' => 14.6, 'longitude' => 121.0],
        ['latitude' => 14.61, 'longitude' => 121.01],
        ['latitude' => 14.61, 'longitude' => 121.01],
        ['latitude' => 14.63, 'longitude' => 121.03],
        ['latitude' => 14.7, 'longitude' => 121.1],
    ];
    $calculation = [
        'coordinates' => $coordinates, 'stop_labels' => ['Repeated synthetic stop', 'Repeated synthetic stop', 'Synthetic C'],
        'result' => [
            'provider' => 'TomTom', 'request_coordinates' => $coordinates,
            'routing_context' => TomTomRoutingService::routingContext('2026-11-20T09:00:00+08:00'),
            'waypoint_order_optimized' => true,
            'optimized_waypoints' => [
                ['providedIndex' => 0, 'optimizedIndex' => 1],
                ['providedIndex' => 1, 'optimizedIndex' => 2],
                ['providedIndex' => 2, 'optimizedIndex' => 0],
            ],
            'comparison_metrics' => ['distance_meters' => 12345.67, 'travel_time_seconds' => 905.5],
            'calculation_started_at' => '2026-11-11T23:59:59Z', 'calculated_at' => '2026-11-12T00:00:00Z',
        ],
    ];

    return compact('service', 'encrypter', 'config', 'context', 'calculation', 'instant');
}

/** @return array<string, mixed> */
function plannedProofDecode(Encrypter $encrypter, string $token): array
{
    return json_decode($encrypter->decryptString($token), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function plannedProofSubmission(array $data): array
{
    return array_intersect_key($data, array_flip([
        'coordinates', 'stop_labels', 'stop_order', 'provider', 'optimization_strategy', 'estimated_distance', 'estimated_time',
    ]));
}

it('issues an authenticated compatible planned proof with raw metrics and explicit requested assumptions', function (string $operation): void {
    extract(plannedProofFixture($operation));
    $token = $service->issue($context, $calculation);
    $data = plannedProofDecode($encrypter, $token);
    $verified = $service->verify($token, $context, plannedProofSubmission($data));
    expect($verified)->toBe($data)
        ->and($data['version'])->toBe(2)->and($data['proof_type'])->toBe('planned_route')
        ->and($data['route_id'])->toBe(7)->and($data['intended_operation'])->toBe($operation)
        ->and($data['expires_at'] - $data['issued_at'])->toBe(1800)
        ->and(Str::isUuid($data['calculation_id']))->toBeTrue()
        ->and($data['departure_context'])->toBe([
            'mode' => 'planned', 'depart_at' => '2026-11-20T09:00:00+08:00', 'timezone' => 'Asia/Manila',
            'source' => 'reservation_schedule', 'verification' => 'requested_context_only',
        ])
        ->and($data['schedule_fingerprint'])->toBe(hash('sha256', json_encode([
            'reservation_id' => 8, 'schedule_date' => '2026-11-20', 'schedule_time' => '09:00:00',
        ], JSON_THROW_ON_ERROR)))
        ->and($data['stop_order'])->toBe([2, 0, 1])
        ->and($data['stop_labels'])->toBe(['Synthetic C', 'Repeated synthetic stop', 'Repeated synthetic stop'])
        ->and($data['coordinates'])->toBe([$calculation['coordinates'][0], $calculation['coordinates'][3], $calculation['coordinates'][1], $calculation['coordinates'][2], $calculation['coordinates'][4]])
        ->and($data['raw_metrics'])->toBe(['distance_meters' => 12345.67, 'travel_time_seconds' => 905.5])
        ->and($data['estimated_distance'])->toBe(12.35)->and($data['estimated_time'])->toBe(16)
        ->and(strlen($token))->toBeLessThanOrEqual(10000)
        ->and($token)->not->toContain('Repeated synthetic stop')
        ->and($data)->not->toHaveKey('optimization_score')
        ->and($service->capabilities())->toBe(['calculation' => true, 'issuance' => true, 'apply' => false, 'save' => false]);
})->with(['update', 'transition']);

it('keeps all independent gates strict and requires every prerequisite for Save readiness', function (): void {
    extract(plannedProofFixture());
    $keys = ['planned_departure_enabled', 'planned_proof_issuance_enabled', 'planned_apply_enabled', 'planned_save_enabled'];
    for ($mask = 0; $mask < 16; $mask++) {
        foreach ($keys as $i => $key) {
            $config->set('services.tomtom.'.$key, (bool) ($mask & (1 << $i)));
        }
        expect($service->capabilities())->toBe([
            'calculation' => (bool) ($mask & 1), 'issuance' => ($mask & 3) === 3,
            'apply' => ($mask & 7) === 7, 'save' => $mask === 15,
        ]);
        if (($mask & 3) !== 3) {
            expect(fn () => $service->issue($context, $calculation))->toThrow(InvalidArgumentException::class, 'issuance is disabled');
        }
    }
    foreach ($keys as $key) {
        foreach (['true', 'false', 1, null, [], 'yes'] as $value) {
            foreach ($keys as $enabled) {
                $config->set('services.tomtom.'.$enabled, true);
            }
            $config->set('services.tomtom.'.$key, $value);
            expect($service->capabilities()['save'])->toBeFalse();
        }
    }
    $service = new PlannedRouteProofService($encrypter, new Repository);
    expect($service->capabilities())->toBe(['calculation' => false, 'issuance' => false, 'apply' => false, 'save' => false]);
});

it('loads strict disabled gate defaults and documents only disabled example values', function (): void {
    $gates = [
        'planned_departure_enabled' => 'FLEET_ROUTE_PLANNED_DEPARTURE_ENABLED',
        'planned_proof_issuance_enabled' => 'FLEET_ROUTE_PLANNED_PROOF_ISSUANCE_ENABLED',
        'planned_apply_enabled' => 'FLEET_ROUTE_PLANNED_APPLY_ENABLED',
        'planned_save_enabled' => 'FLEET_ROUTE_PLANNED_SAVE_ENABLED',
    ];
    $example = file_get_contents(base_path('.env.example'));
    foreach ($gates as $key => $variable) {
        expect(config('services.tomtom.'.$key))->toBeFalse()
            ->and($example)->toContain($variable.'=false');
    }
    expect(app(PlannedRouteProofService::class)->capabilities())->toBe([
        'calculation' => false, 'issuance' => false, 'apply' => false, 'save' => false,
    ])->and(app('db')->getConnections())->toBe([]);
});

it('rejects altered protected proof metadata and refuses legacy proofs', function (string $field, mixed $value): void {
    extract(plannedProofFixture());
    $token = $service->issue($context, $calculation);
    $data = plannedProofDecode($encrypter, $token);
    $submitted = plannedProofSubmission($data);
    data_set($data, $field, $value);
    $modified = $encrypter->encryptString(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    expect(fn () => $service->verify($modified, $context, $submitted))->toThrow(InvalidArgumentException::class);
})->with([
    ['version', 1], ['version', '2'], ['version', null], ['proof_type', 'legacy'],
    ['user_id', 99], ['reservation_id', 99], ['route_id', 99], ['intended_operation', 'create'],
    ['departure_context.depart_at', '2026-11-20T10:00:00+08:00'], ['departure_context.mode', 'current'],
    ['departure_context.verification', 'provider_verified'], ['schedule_fingerprint', 'wrong'],
    ['provider', 'OSRM'], ['estimated_distance', 99], ['estimated_time', 15],
    ['stop_order', [2, 1, 0]], ['stop_labels.1', 'Changed synthetic label'],
    ['coordinates.1.latitude', 15], ['origin_latitude', 'invalid'],
    ['expires_at', 9999999999], ['issued_at', '1794441600'],
    ['calculation_id', 'not a UUID'], ['routing_context.traffic', false],
    ['raw_metrics.distance_meters', null], ['unexpected_client_score', 100],
]);

it('rejects changed authorized identities current schedule and operation context', function (string $field, mixed $value): void {
    extract(plannedProofFixture());
    $token = $service->issue($context, $calculation);
    $data = plannedProofDecode($encrypter, $token);
    $context[$field] = $value;
    expect(fn () => $service->verify($token, $context, plannedProofSubmission($data)))->toThrow(InvalidArgumentException::class);
})->with([
    ['user_id', 18], ['reservation_id', 9], ['route_id', 8], ['route_reservation_id', 9],
    ['intended_operation', 'transition'], ['intended_operation', 'create'],
    ['route_status', 'Ready For Dispatch'], ['route_status', 'Completed'],
    ['route_status', 'Draft'], ['expected_estimate_revision', 1], ['expected_estimate_revision', null],
    ['expected_estimate_revision', -1], ['expected_estimate_revision', '0'], ['target_status', 'Draft'],
    ['schedule_date', '2026-11-21'], ['schedule_time', '10:00:00'], ['schedule_time', ''],
    ['schedule_date', '2026-11-11'],
]);

it('rejects modified ordered route submissions including coincident stop occurrences', function (string $field, mixed $value): void {
    extract(plannedProofFixture());
    $token = $service->issue($context, $calculation);
    $submitted = plannedProofSubmission(plannedProofDecode($encrypter, $token));
    data_set($submitted, $field, $value);
    expect(fn () => $service->verify($token, $context, $submitted))->toThrow(InvalidArgumentException::class);
})->with([
    ['coordinates.1.latitude', 14.62], ['coordinates', []],
    ['stop_order', [2, 1, 0]], ['stop_labels', ['Synthetic C', 'Repeated synthetic stop']],
    ['stop_labels.1', 'Different label'], ['provider', 'OSRM'],
    ['optimization_strategy', 'Unknown'], ['estimated_time', 15], ['estimated_time', null],
    ['estimated_distance', 12.34], ['estimated_distance', '12.35'],
]);

it('rejects missing trusted fields invalid metrics options coordinates and permutations', function (string $field, mixed $value): void {
    extract(plannedProofFixture());
    data_set($calculation, $field, $value);
    expect(fn () => $service->issue($context, $calculation))->toThrow(InvalidArgumentException::class);
})->with([
    ['coordinates', null], ['coordinates.0.latitude', '14.6'], ['coordinates.0.latitude', NAN],
    ['coordinates.0.latitude', INF], ['coordinates.0.latitude', 91],
    ['stop_labels', []], ['stop_labels.0', ''], ['stop_labels.0', str_repeat('x', 256)],
    ['result', null], ['result.provider', 'OSRM'], ['result.routing_context', null],
    ['result.routing_context.depart_at', '2026-11-20T10:00:00+08:00'],
    ['result.routing_context.travel_mode', 'truck'], ['result.routing_context.traffic', false],
    ['result.request_coordinates', []], ['result.waypoint_order_optimized', false],
    ['result.optimized_waypoints', null], ['result.optimized_waypoints', []],
    ['result.optimized_waypoints.0.providedIndex', 1], ['result.optimized_waypoints.0.optimizedIndex', 2],
    ['result.optimized_waypoints.0.providedIndex', '0'], ['result.optimized_waypoints.0.optimizedIndex', -1],
    ['result.optimized_waypoints.0.providedIndex', 3],
    ['result.comparison_metrics', null], ['result.comparison_metrics.distance_meters', null],
    ['result.comparison_metrics.distance_meters', '12000'], ['result.comparison_metrics.distance_meters', -1],
    ['result.comparison_metrics.distance_meters', INF], ['result.comparison_metrics.distance_meters', NAN],
    ['result.comparison_metrics.travel_time_seconds', -1], ['result.comparison_metrics.travel_time_seconds', INF],
    ['result.comparison_metrics.travel_time_seconds', NAN], ['result.comparison_metrics.travel_time_seconds', PHP_FLOAT_MAX],
    ['result.comparison_metrics.distance_meters', PHP_FLOAT_MAX],
    ['result.calculation_started_at', null], ['result.calculation_started_at', '2026-11-11T23:59:29Z'],
    ['result.calculated_at', '2026-11-12T00:00:02Z'],
    ['result.calculated_at', '2026-02-30T00:00:00Z'], ['result.calculated_at', 'yesterday'],
]);

it('preserves zero metrics and derives compatible rounded storage values only after validation', function (float $meters, float $seconds, float $kilometers, int $minutes): void {
    extract(plannedProofFixture());
    $calculation['result']['comparison_metrics'] = ['distance_meters' => $meters, 'travel_time_seconds' => $seconds];
    $calculation['result']['optimization_score'] = 100;
    $data = plannedProofDecode($encrypter, $service->issue($context, $calculation));
    expect($data['raw_metrics'])->toBe(['distance_meters' => $meters, 'travel_time_seconds' => $seconds])
        ->and($data['estimated_distance'])->toBe($kilometers)->and($data['estimated_time'])->toBe($minutes)
        ->and($data)->not->toHaveKey('optimization_score');
})->with([[0.0, 0.0, 0.0, 0], [12000.0, 900.0, 12.0, 15], [12345.67, 901.5, 12.35, 15], [12345.67, 905.5, 12.35, 16], [1.0, 1.0, 0.0, 0]]);

it('preserves optional zero and one stop mappings and valid identity permutations', function (int $count): void {
    extract(plannedProofFixture());
    $calculation['coordinates'] = [$calculation['coordinates'][0], ...array_slice($calculation['coordinates'], 1, $count), $calculation['coordinates'][4]];
    $calculation['stop_labels'] = array_slice($calculation['stop_labels'], 0, $count);
    $calculation['result']['request_coordinates'] = $calculation['coordinates'];
    $calculation['result']['optimized_waypoints'] = $count < 2 ? [] : [
        ['providedIndex' => 0, 'optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 1],
    ];
    $token = $service->issue($context, $calculation);
    $data = plannedProofDecode($encrypter, $token);
    expect($service->verify($token, $context, plannedProofSubmission($data)))->toBe($data)
        ->and($data['stop_order'])->toBe($count === 0 ? [] : range(0, $count - 1));
})->with([0, 1, 2]);

it('authenticates tokens rejects tampering wrong keys legacy data and oversized input', function (): void {
    extract(plannedProofFixture());
    $token = $service->issue($context, $calculation);
    $data = plannedProofDecode($encrypter, $token);
    $submitted = plannedProofSubmission($data);
    $envelope = json_decode(base64_decode($token), true, flags: JSON_THROW_ON_ERROR);
    $envelope['value'][0] = $envelope['value'][0] === 'A' ? 'B' : 'A';
    $tampered = base64_encode(json_encode($envelope, JSON_THROW_ON_ERROR));
    foreach ([$tampered, '', str_repeat('a', 10001), $encrypter->encryptString('invalid json'), $encrypter->encryptString('null'), $encrypter->encryptString(json_encode(['user_id' => 17, 'reservation_id' => 8, 'issued_at' => $instant->timestamp]))] as $invalid) {
        expect(fn () => $service->verify($invalid, $context, $submitted))->toThrow(InvalidArgumentException::class);
    }
    $other = new PlannedRouteProofService(new Encrypter(str_repeat('t', 32), 'AES-256-CBC'), $config, fn (): CarbonImmutable => $instant);
    expect(fn () => $other->verify($token, $context, $submitted))->toThrow(InvalidArgumentException::class);
});

it('accepts numeric coordinate identity through browser JSON and provider float normalization', function (): void {
    extract(plannedProofFixture());
    $calculation['coordinates'][0] = ['latitude' => 14, 'longitude' => 121];
    $calculation['result']['request_coordinates'][0] = ['latitude' => 14.0, 'longitude' => 121.0];
    $token = $service->issue($context, $calculation);
    $data = plannedProofDecode($encrypter, $token);
    $submitted = json_decode(json_encode(plannedProofSubmission($data), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($submitted['coordinates'][0])->toBe(['latitude' => 14, 'longitude' => 121])
        ->and($service->verify($token, $context, $submitted))->toBe($data);
});

it('rejects oversized issued tokens without truncation or registry writes', function (): void {
    extract(plannedProofFixture());
    $points = [$calculation['coordinates'][0]];
    $labels = $mapping = [];
    for ($index = 0; $index < 30; $index++) {
        $points[] = ['latitude' => 14.61, 'longitude' => 121.01];
        $labels[] = str_repeat('Synthetic stop ', 15).$index;
        $mapping[] = ['providedIndex' => $index, 'optimizedIndex' => $index];
    }
    $points[] = $calculation['coordinates'][4];
    $calculation['coordinates'] = $calculation['result']['request_coordinates'] = $points;
    $calculation['stop_labels'] = $labels;
    $calculation['result']['optimized_waypoints'] = $mapping;
    expect(fn () => $service->issue($context, $calculation))->toThrow(InvalidArgumentException::class, 'size limit');
});

it('rejects authenticated proofs with required fields removed', function (string $field): void {
    extract(plannedProofFixture());
    $token = $service->issue($context, $calculation);
    $data = plannedProofDecode($encrypter, $token);
    $submitted = plannedProofSubmission($data);
    unset($data[$field]);
    $incomplete = $encrypter->encryptString(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    expect(fn () => $service->verify($incomplete, $context, $submitted))->toThrow(InvalidArgumentException::class);
})->with([
    'version', 'proof_type', 'user_id', 'reservation_id', 'route_id', 'intended_operation',
    'issued_at', 'expires_at', 'calculation_id', 'departure_context', 'schedule_fingerprint',
    'input_coordinates', 'input_stop_labels', 'coordinates', 'stop_labels', 'stop_order',
    'provider', 'routing_context', 'raw_metrics', 'optimization_strategy', 'estimated_distance',
    'estimated_time', 'calculation_started_at', 'calculated_at',
]);

it('captures one clock instant and rejects invalid injected clocks without side effects', function (): void {
    extract(plannedProofFixture());
    $reads = 0;
    $counted = new PlannedRouteProofService($encrypter, $config, function () use (&$reads, $instant): CarbonImmutable {
        $reads++;

        return $instant;
    });
    $token = $counted->issue($context, $calculation);
    expect($reads)->toBe(1);
    $data = plannedProofDecode($encrypter, $token);
    $counted->verify($token, $context, plannedProofSubmission($data));
    expect($reads)->toBe(2);
    $invalid = new PlannedRouteProofService($encrypter, $config, fn (): string => 'not a clock');
    expect(fn () => $invalid->issue($context, $calculation))->toThrow(UnexpectedValueException::class)
        ->and($instant->toIso8601String())->toBe('2026-11-12T00:00:00+00:00');
});

it('retains the thirty minute lifetime including the existing exact expiration boundary', function (int $elapsed, bool $valid): void {
    extract(plannedProofFixture());
    $token = $service->issue($context, $calculation);
    $data = plannedProofDecode($encrypter, $token);
    $later = new PlannedRouteProofService($encrypter, $config, fn (): CarbonImmutable => $instant->addSeconds($elapsed));
    if ($valid) {
        expect($later->verify($token, $context, plannedProofSubmission($data)))->toBe($data);
    } else {
        expect(fn () => $later->verify($token, $context, plannedProofSubmission($data)))->toThrow(InvalidArgumentException::class, 'expired');
    }
})->with([[0, true], [1800, true], [1801, false], [-61, false]]);

it('explicitly permits stateless replay while minting separate calculation identities for separate issues', function (): void {
    extract(plannedProofFixture());
    $token = $service->issue($context, $calculation);
    $data = plannedProofDecode($encrypter, $token);
    expect($service->verify($token, $context, plannedProofSubmission($data)))
        ->toBe($service->verify($token, $context, plannedProofSubmission($data)))
        ->and(plannedProofDecode($encrypter, $service->issue($context, $calculation))['calculation_id'])->not->toBe($data['calculation_id']);
    $config->set('services.tomtom.planned_proof_issuance_enabled', false);
    expect($service->verify($token, $context, plannedProofSubmission($data)))->toBe($data)
        ->and($service->capabilities()['save'])->toBeFalse();
});

it('keeps generated v2 tokens compatible with the protected existing validator without activating Save', function (): void {
    extract(plannedProofFixture());
    CarbonImmutable::setTestNow($instant);
    Carbon::setTestNow($instant);
    try {
        $token = $service->issue($context, $calculation);
        $data = plannedProofDecode($encrypter, $token);
        $user = new User;
        $user->setRawAttributes(['id' => $context['user_id'], 'role' => 'fleet_manager'], true);
        $reservation = new Reservation;
        $reservation->setRawAttributes(['id' => 8, 'schedule_date' => $context['schedule_date'], 'schedule_time' => $context['schedule_time']], true);
        $route = new RoutePlan;
        $route->setRawAttributes(['id' => 7, 'reservation_id' => 8, 'status' => 'Planned'], true);
        $route->setRelation('reservation', $reservation);
        $request = Request::create('/', 'POST', ['planned_stop_order' => $data['stop_order'], 'optimization_proof' => $token]);
        $request->setUserResolver(fn (): User => $user);
        $validated = array_intersect_key($data, array_flip(['origin_latitude', 'origin_longitude', 'destination_latitude', 'destination_longitude', 'estimated_distance', 'estimated_time', 'optimization_strategy']));
        $validated['stops'] = array_map(fn (array $point, string $label): array => [...$point, 'location' => $label], array_slice($data['coordinates'], 1, -1), $data['stop_labels']);
        app()->instance(RouteDepartureContextService::class, new RouteDepartureContextService(fn (): CarbonImmutable => $instant));
        $controller = app(RoutePlanController::class);
        $connections = count(app('db')->getConnections());
        (new ReflectionMethod(RoutePlanController::class, 'assertPlannedOptimizationProof'))->invoke($controller, $request, $reservation, $route, $validated, $data);
        expect(count(app('db')->getConnections()))->toBe($connections);
        $this->actingAs($user);
        app()->instance('encrypter', $encrypter);
        Crypt::clearResolvedInstance('encrypter');
        $validated['optimization_proof'] = $token;
        expect(fn () => (new ReflectionMethod(RoutePlanController::class, 'assertOptimizationProof'))->invoke($controller, $route, $validated))
            ->toThrow(Exception::class, 'could not be verified');
        app('config')->set('services.tomtom.planned_departure_enabled', true);
        try {
            (new ReflectionMethod(RoutePlanController::class, 'guardPlannedRouteSave'))->invoke($controller, $request, $reservation, $route, $validated);
            $this->fail('No generated token may activate planned Save.');
        } catch (HttpResponseException $exception) {
            expect($exception->getResponse()->getData(true)['code'])->toBe('ROUTE_PLANNED_SAVE_DISABLED');
        }
        expect(count(app('db')->getConnections()))->toBe($connections);
    } finally {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
    }
});
