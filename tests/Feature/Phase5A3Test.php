<?php

use App\Models\AuditLog;
use App\Models\Dispatch;
use App\Models\Driver;
use App\Models\DriverVehicleQualification;
use App\Models\Reservation;
use App\Models\RoutePlan;
use App\Models\User;
use App\Models\Vehicle;
use App\Policies\ReservationPolicy;
use App\Policies\RoutePlanPolicy;
use App\Services\RouteComparisonService;
use App\Services\TomTomRoutingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

it('allows twenty shared routing attempts and rejects variations and new sessions for the same user', function () {
    $this->freezeTime();
    config(['services.tomtom.key' => 'synthetic-rate-limit-test-only']);
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => Http::response(p53ComparisonBody($request, [], 1000, 120)));
    $user = p53User();
    $payload = ['coordinates' => [['latitude' => 14, 'longitude' => 121], ['latitude' => 15, 'longitude' => 122]]];

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $this->actingAs($user)->withHeader('X-Forwarded-For', '192.0.2.'.($attempt + 1))
            ->postJson(route('route-planning.traffic-route'), $payload)
            ->assertSuccessful()->assertJsonStructure(['optimization_proof']);
    }
    Http::assertSentCount(20);
    $this->flushSession();
    $rejected = $this->actingAs($user)->withHeader('X-Forwarded-For', '198.51.100.1')
        ->postJson(route('route-planning.traffic-route'), [
            ...$payload,
            'coordinates' => [['latitude' => 16, 'longitude' => 123], ['latitude' => 17, 'longitude' => 124]],
            'include_comparison' => true,
            'reservation_id' => 999999,
        ])->assertStatus(429)->assertJsonPath('success', false)
        ->assertJsonPath('code', 'ROUTE_RATE_LIMITED')->assertJsonPath('scope', 'all')
        ->assertJsonPath('retry_after', 60)->assertHeader('Retry-After', '60')
        ->assertHeader('X-RateLimit-Limit', '20')->assertHeader('X-RateLimit-Remaining', '0');
    expect($rejected->getContent())->not->toContain('all:'.$user->id);
    Http::assertSentCount(20);

    $this->actingAs(p53User())->postJson(route('route-planning.traffic-route'), $payload)->assertSuccessful();
    Http::assertSentCount(21);
    $this->travel(61)->seconds();
    $this->actingAs($user)->postJson(route('route-planning.traffic-route'), $payload)->assertSuccessful();
    Http::assertSentCount(22);
});

it('charges comparison requests to both buckets without resetting on request variations', function (string $role) {
    $this->freezeTime();
    config(['services.tomtom.key' => 'synthetic-rate-limit-test-only']);
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => Http::response(p53ComparisonBody($request, [2, 0, 1], 1000, 120)));
    $user = User::factory()->create(['role' => $role]);
    $payload = p53ComparisonPayload(p53Reservation());

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->actingAs($user)->postJson(route('route-planning.traffic-route'), $payload)
            ->assertSuccessful()->assertJsonPath('comparison.status', 'available');
    }
    Http::assertSentCount(12);
    $this->postJson(route('route-planning.traffic-route'), [
        ...$payload, 'reservation_id' => p53Reservation()->id, 'include_comparison' => '1',
    ])->assertStatus(429)->assertJsonPath('scope', 'comparison')
        ->assertJsonPath('retry_after', 60)->assertHeader('Retry-After', '60');
    Http::assertSentCount(12);

    for ($attempt = 0; $attempt < 14; $attempt++) {
        $this->postJson(route('route-planning.traffic-route'), [...$payload, 'include_comparison' => false])
            ->assertSuccessful()->assertJsonMissingPath('comparison');
    }
    $this->postJson(route('route-planning.traffic-route'), [...$payload, 'include_comparison' => false])
        ->assertStatus(429)->assertJsonPath('scope', 'all');
    Http::assertSentCount(26);
    $this->travel(61)->seconds();
    $this->postJson(route('route-planning.traffic-route'), $payload)->assertSuccessful();
    Http::assertSentCount(28);
})->with(['fleet_manager', 'dispatcher']);

it('counts admitted invalid routing requests and leaves other route endpoints unthrottled', function () {
    $this->freezeTime();
    Http::fake();
    $this->actingAs(p53User());
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $this->postJson(route('route-planning.traffic-route'), [])->assertUnprocessable();
    }
    $this->postJson(route('route-planning.traffic-route'), [])->assertStatus(429);
    $this->get(route('route-planning'))->assertSuccessful();
    Http::assertNothingSent();
});

it('keeps driver navigation routing available within the shared limit', function () {
    config(['services.tomtom.key' => 'synthetic-rate-limit-test-only']);
    Http::fake(fn (Request $request) => Http::response(p53ComparisonBody($request, [], 1000, 120)));
    $user = User::factory()->create(['role' => 'driver']);
    $before = Dispatch::count();
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $this->actingAs($user)->postJson(route('route-planning.traffic-route'), [
            'coordinates' => [['latitude' => 14, 'longitude' => 121], ['latitude' => 15, 'longitude' => 122]],
        ])->assertSuccessful();
    }
    expect(Dispatch::count())->toBe($before);
    Http::assertSentCount(4);
});

it('does not contact a provider when the limiter cache fails', function () {
    Http::fake();
    $limiter = Mockery::mock(Illuminate\Cache\RateLimiter::class)->makePartial();
    $limiter->shouldReceive('limiter')->with('route-traffic')->andReturn(RateLimiter::limiter('route-traffic'));
    $limiter->shouldReceive('tooManyAttempts')->andThrow(new RuntimeException('Synthetic limiter cache failure'));
    $this->app->instance(Illuminate\Cache\RateLimiter::class, $limiter);

    $this->actingAs(p53User())->postJson(route('route-planning.traffic-route'), [
        'coordinates' => [['latitude' => 14, 'longitude' => 121], ['latitude' => 15, 'longitude' => 122]],
    ])->assertStatus(500);
    Http::assertNothingSent();
});

function p53ComparisonPayload(Reservation $reservation, int $stopCount = 3): array
{
    $stops = [];
    for ($index = 0; $index < $stopCount; $index++) {
        $stops[] = ['latitude' => 14.601 + $index / 1000, 'longitude' => 121.001 + $index / 1000];
    }

    return [
        'reservation_id' => $reservation->id,
        'include_comparison' => true,
        'depart_at' => now()->addDay()->toIso8601String(),
        'coordinates' => [
            ['latitude' => 14.5995, 'longitude' => 120.9842],
            ...$stops,
            ['latitude' => 14.6091, 'longitude' => 121.0223],
        ],
    ];
}

function p53ComparisonBody(Request $request, array $order, float $distance, float $duration): array
{
    preg_match('~/calculateRoute/([^/]+)/json$~', rawurldecode(parse_url($request->url(), PHP_URL_PATH)), $matches);
    $points = array_map(function (string $pair): array {
        [$lat, $lng] = array_map('floatval', explode(',', $pair));

        return ['latitude' => $lat, 'longitude' => $lng];
    }, explode(':', $matches[1]));
    $optimized = $request['computeBestOrder'] === 'true';
    $mapping = [];
    $ordered = [$points[0]];
    foreach ($order as $position => $index) {
        $mapping[] = ['providedIndex' => $index, 'optimizedIndex' => $position];
        $ordered[] = $points[$index + 1];
    }
    $ordered[] = $points[array_key_last($points)];

    return [
        'optimizedWaypoints' => $optimized ? $mapping : [],
        'routes' => [[
            'summary' => [
                'lengthInMeters' => $distance,
                'travelTimeInSeconds' => $duration,
                'trafficDelayInSeconds' => 0,
                'noTrafficTravelTimeInSeconds' => $duration,
                'liveTrafficIncidentsTravelTimeInSeconds' => $duration,
            ],
            'legs' => [['points' => $optimized ? $ordered : $points]],
        ]],
    ];
}

it('compares two TomTom orders with a frozen context raw measurements and unchanged proof', function (array $order, bool $duplicateCoordinates) {
    config(['services.tomtom.key' => 'synthetic-comparison-test-only']);
    $reservation = p53Reservation();
    $user = p53User();
    $payload = p53ComparisonPayload($reservation, count($order));
    if ($duplicateCoordinates) {
        foreach (range(1, count($order)) as $index) {
            $payload['coordinates'][$index] = $payload['coordinates'][1];
        }
    }
    $requests = [];
    Http::fake(function (Request $request, array $options) use (&$requests, $order) {
        $requests[] = $request;
        expect($options['timeout'])->toBeLessThanOrEqual(7)
            ->and($options['connect_timeout'])->toBeLessThanOrEqual(2);
        $optimized = $request['computeBestOrder'] === 'true';

        return Http::response(p53ComparisonBody($request, $order, $optimized ? 12500.25 : 15000.75, $optimized ? 1680.25 : 1800.5));
    });
    $audits = AuditLog::count();
    $response = $this->actingAs($user)->postJson(route('route-planning.traffic-route'), $payload)
        ->assertSuccessful()
        ->assertJsonPath('comparison.status', 'available')
        ->assertJsonPath('comparison.candidate_order', $order)
        ->assertJsonPath('comparison.original.distance_meters', 15000.75)
        ->assertJsonPath('comparison.candidate.distance_meters', 12500.25)
        ->assertJsonPath('comparison.difference.distance_meters', 2500.5)
        ->assertJsonPath('comparison.difference.travel_time_seconds', 120.25);
    Http::assertSentCount(2);
    expect($requests[0]['computeBestOrder'])->toBe('true')
        ->and($requests[1]['computeBestOrder'])->toBe('false');
    foreach (['departAt', 'traffic', 'routeType', 'travelMode', 'computeTravelTimeFor'] as $field) {
        expect($requests[0][$field])->toBe($requests[1][$field]);
    }
    expect(parse_url($requests[0]->url(), PHP_URL_PATH))->toBe(parse_url($requests[1]->url(), PHP_URL_PATH))
        ->and($response->json('comparison.source_coordinates'))->toEqual($payload['coordinates'])
        ->and($response->json('comparison.routing_context.depart_at'))->toBe($payload['depart_at'])
        ->and($response->json('comparison.order_changed'))->toBe($order !== range(0, count($order) - 1));
    $proof = json_decode(Crypt::decryptString($response->json('optimization_proof')), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($proof))->toBe(['user_id', 'reservation_id', 'issued_at', 'origin_latitude', 'origin_longitude', 'destination_latitude', 'destination_longitude', 'coordinates', 'estimated_distance', 'estimated_time', 'optimization_strategy', 'provider'])
        ->and($proof['coordinates'])->toEqual([$payload['coordinates'][0], ...array_map(fn (int $index): array => $payload['coordinates'][$index + 1], $order), $payload['coordinates'][array_key_last($payload['coordinates'])]])
        ->and(AuditLog::count())->toBe($audits)
        ->and(RoutePlan::count())->toBe(0);
})->with([
    'two-stop reversal' => [[1, 0], false],
    'three-stop cycle' => [[2, 0, 1], false],
    'identity' => [[0, 1, 2], false],
    'duplicate coordinates remain separate occurrences' => [[2, 0, 1], true],
]);

it('keeps comparison optional and preserves a single legacy routing call', function (bool $includeFlag) {
    $routing = Mockery::mock(TomTomRoutingService::class);
    $points = p53UpdatePayload();
    $coordinates = [
        ['latitude' => $points['origin_latitude'], 'longitude' => $points['origin_longitude']],
        ['latitude' => $points['destination_latitude'], 'longitude' => $points['destination_longitude']],
    ];
    $routing->shouldReceive('calculateRoute')->once()->with($coordinates, null)->andReturn(p53TomTomResult());
    $this->app->instance(TomTomRoutingService::class, $routing);
    $this->actingAs(p53User())->postJson(route('route-planning.traffic-route'), [
        'coordinates' => $coordinates,
        ...($includeFlag ? ['include_comparison' => false] : []),
    ])->assertSuccessful()->assertJsonMissingPath('comparison');
})->with([false, true]);

it('preserves a valid candidate when the baseline fails without retrying', function () {
    config(['services.tomtom.key' => 'synthetic-comparison-test-only']);
    $payload = p53ComparisonPayload(p53Reservation());
    Http::fake(function (Request $request) {
        if ($request['computeBestOrder'] === 'false') {
            throw new ConnectionException('Synthetic private provider failure');
        }

        return Http::response(p53ComparisonBody($request, [2, 0, 1], 12500, 1200));
    });
    $response = $this->actingAs(p53User())->postJson(route('route-planning.traffic-route'), $payload)
        ->assertSuccessful()->assertJsonPath('comparison.status', 'unavailable')
        ->assertJsonPath('provider', 'TomTom')->assertJsonPath('distance_meters', 12500);
    expect($response->json('optimization_proof'))->toBeString()
        ->and($response->json('comparison.difference'))->toBeNull()
        ->and($response->getContent())->not->toContain('Synthetic private provider failure');
});

it('does not request a baseline when the candidate provider fails', function () {
    config(['services.tomtom.key' => 'synthetic-comparison-test-only']);
    Http::fake(['*' => Http::response(['message' => 'Synthetic failure'], 503)]);
    $this->actingAs(p53User())->postJson(route('route-planning.traffic-route'), p53ComparisonPayload(p53Reservation()))
        ->assertStatus(502)->assertJsonMissingPath('optimization_proof');
    Http::assertSentCount(1);
});

it('requires explicit comparison capability before any provider call', function (string $role) {
    Http::fake();
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->postJson(route('route-planning.traffic-route'), p53ComparisonPayload(p53Reservation()))
        ->assertForbidden();
    Http::assertNothingSent();
})->with(['driver', 'it_admin', 'department_head']);

it('checks reservation and associated route policies before comparison calls', function (bool $denyReservation) {
    $reservation = p53Reservation();
    p53Route($reservation);
    if ($denyReservation) {
        $policy = new class extends ReservationPolicy
        {
            public function view(User $user, Reservation $reservation): bool
            {
                return false;
            }
        };
        Gate::policy(Reservation::class, $policy::class);
    } else {
        $policy = new class extends RoutePlanPolicy
        {
            public function view(User $user, RoutePlan $routePlan): bool
            {
                return false;
            }
        };
        Gate::policy(RoutePlan::class, $policy::class);
    }
    Http::fake();
    $this->actingAs(p53User())->postJson(route('route-planning.traffic-route'), p53ComparisonPayload($reservation))->assertForbidden();
    Http::assertNothingSent();
})->with([true, false]);

it('requires a reservation for comparison and rejects malformed opt-in values', function (mixed $flag) {
    Http::fake();
    $this->actingAs(p53User())->postJson(route('route-planning.traffic-route'), [
        'coordinates' => [['latitude' => 14, 'longitude' => 121], ['latitude' => 15, 'longitude' => 122]],
        'include_comparison' => $flag,
    ])->assertUnprocessable();
    Http::assertNothingSent();
})->with([[true], ['yes'], [[]], [null]]);

it('reports signed and zero differences without fabricated percentages', function (float $originalDistance, float $candidateDistance, float $originalTime, float $candidateTime) {
    config(['services.tomtom.key' => 'synthetic-comparison-test-only']);
    Http::fake(function (Request $request) use ($originalDistance, $candidateDistance, $originalTime, $candidateTime) {
        $optimized = $request['computeBestOrder'] === 'true';

        return Http::response(p53ComparisonBody($request, [0, 1, 2], $optimized ? $candidateDistance : $originalDistance, $optimized ? $candidateTime : $originalTime));
    });
    $response = $this->actingAs(p53User())->postJson(route('route-planning.traffic-route'), p53ComparisonPayload(p53Reservation()))
        ->assertSuccessful()->assertJsonPath('comparison.status', 'available')->assertJsonPath('comparison.order_changed', false);
    foreach (['distance_meters' => [$originalDistance, $candidateDistance], 'travel_time_seconds' => [$originalTime, $candidateTime]] as $key => [$original, $candidate]) {
        expect($response->json('comparison.difference.'.$key))->toEqual($original - $candidate)
            ->and($response->json('comparison.difference.'.$key.'_percent'))->toEqual($original > 0 ? (($original - $candidate) / $original) * 100 : null);
    }
    expect($response->json('comparison.reason'))->toContain('not verified savings');
})->with([
    [15000, 12000, 1500, 1200],
    [9000, 10000, 500, 600],
    [10000, 10000, 600, 600],
    [0, 10000, 0, 600],
]);

function p53ComparableResult(array $coordinates, string $departAt, bool $optimized = true): array
{
    return [
        'provider' => 'TomTom',
        'routing_context' => TomTomRoutingService::routingContext($departAt),
        'request_coordinates' => array_map(fn (array $point): array => ['latitude' => (float) $point['latitude'], 'longitude' => (float) $point['longitude']], $coordinates),
        'waypoint_order_optimized' => $optimized,
        'comparison_metrics' => ['distance_meters' => 12000, 'travel_time_seconds' => 1200],
        'optimized_waypoints' => $optimized ? [['providedIndex' => 0, 'optimizedIndex' => 1], ['providedIndex' => 1, 'optimizedIndex' => 2], ['providedIndex' => 2, 'optimizedIndex' => 0]] : [],
        'calculation_started_at' => now()->toISOString(),
        'calculated_at' => now()->toISOString(),
    ];
}

it('rejects incomplete malformed stale or mismatched comparison results', function (array $changes, bool $baselineInvalid) {
    $payload = p53ComparisonPayload(p53Reservation());
    $valid = p53ComparableResult($payload['coordinates'], $payload['depart_at']);
    $baseline = p53ComparableResult($payload['coordinates'], $payload['depart_at'], false);
    $routing = Mockery::mock(TomTomRoutingService::class);
    if ($baselineInvalid) {
        $routing->shouldReceive('calculateRoute')->once()->andReturn(array_replace_recursive($baseline, $changes));
    } else {
        $valid = array_replace_recursive($valid, $changes);
        $routing->shouldNotReceive('calculateRoute');
    }
    $comparison = (new RouteComparisonService($routing))->compare($payload['coordinates'], $valid, $payload['depart_at'], hrtime(true) / 1e9);
    expect($comparison['status'])->toBe('unavailable')->and($comparison['difference'])->toBeNull();
})->with([
    'mixed provider' => [['provider' => 'OSRM'], false],
    'different traffic assumption' => [['routing_context' => ['traffic' => false]], false],
    'different departure' => [['routing_context' => ['depart_at' => '2000-01-01T00:00:00Z']], false],
    'different profile' => [['routing_context' => ['travel_mode' => 'truck']], false],
    'different route objective' => [['routing_context' => ['route_type' => 'shortest']], false],
    'different API version' => [['routing_context' => ['api_version' => '2']], false],
    'changed endpoint coordinate' => [['request_coordinates' => [0 => ['latitude' => 0]]], false],
    'missing metric' => [['comparison_metrics' => ['distance_meters' => null]], false],
    'string metric' => [['comparison_metrics' => ['distance_meters' => '12000']], false],
    'boolean metric' => [['comparison_metrics' => ['distance_meters' => false]], false],
    'negative metric' => [['comparison_metrics' => ['travel_time_seconds' => -1]], false],
    'nonfinite metric' => [['comparison_metrics' => ['distance_meters' => INF]], false],
    'NaN metric' => [['comparison_metrics' => ['distance_meters' => NAN]], false],
    'stale timestamp' => [['calculation_started_at' => '2000-01-01T00:00:00Z'], false],
    'missing timestamp' => [['calculated_at' => null], false],
    'future timestamp' => [['calculated_at' => '2099-01-01T00:00:00Z'], false],
    'duplicate indices' => [['optimized_waypoints' => [0 => ['optimizedIndex' => 0]]], false],
    'baseline provider differs' => [['provider' => 'OSRM'], true],
    'baseline context differs' => [['routing_context' => ['traffic' => false]], true],
    'baseline reordered unexpectedly' => [['optimized_waypoints' => [['providedIndex' => 0, 'optimizedIndex' => 2], ['providedIndex' => 1, 'optimizedIndex' => 0], ['providedIndex' => 2, 'optimizedIndex' => 1]]], true],
    'baseline metric missing' => [['comparison_metrics' => ['travel_time_seconds' => null]], true],
]);

it('skips the baseline when the shared request budget is exhausted', function () {
    $payload = p53ComparisonPayload(p53Reservation());
    $routing = Mockery::mock(TomTomRoutingService::class);
    $routing->shouldNotReceive('calculateRoute');
    $comparison = (new RouteComparisonService($routing))->compare($payload['coordinates'], p53ComparableResult($payload['coordinates'], $payload['depart_at']), $payload['depart_at'], hrtime(true) / 1e9 - 15);
    expect($comparison['status'])->toBe('unavailable')->and($comparison['reason'])->toContain('budget');
});

it('uses only the remaining baseline budget and discards a late baseline result', function (bool $late) {
    $payload = p53ComparisonPayload(p53Reservation());
    $routing = Mockery::mock(TomTomRoutingService::class);
    $routing->shouldReceive('calculateRoute')->once()->with($payload['coordinates'], $payload['depart_at'], false, 4.0)
        ->andReturn(p53ComparableResult($payload['coordinates'], $payload['depart_at'], false));
    $comparison = new class($routing, $late) extends RouteComparisonService
    {
        private int $checks = 0;

        public function __construct(TomTomRoutingService $routing, private bool $late)
        {
            parent::__construct($routing);
        }

        protected function elapsedSeconds(float $startedAt): float
        {
            $this->checks++;

            return $this->checks === 1 ? 10.0 : ($this->late ? 14.01 : 13.5);
        }
    };
    $result = $comparison->compare($payload['coordinates'], p53ComparableResult($payload['coordinates'], $payload['depart_at']), $payload['depart_at'], 0.0);
    expect($result['status'])->toBe($late ? 'unavailable' : 'available')
        ->and($result['difference'] === null)->toBe($late);
})->with([false, true]);

it('freezes a single explicit departure time when comparison has no supplied departure', function () {
    config(['services.tomtom.key' => 'synthetic-comparison-test-only']);
    $this->freezeTime();
    $payload = p53ComparisonPayload(p53Reservation());
    unset($payload['depart_at']);
    $departures = [];
    Http::fake(function (Request $request) use (&$departures) {
        $departures[] = $request['departAt'];

        return Http::response(p53ComparisonBody($request, [2, 0, 1], 12000, 1200));
    });
    $this->actingAs(p53User())->postJson(route('route-planning.traffic-route'), $payload)->assertSuccessful()
        ->assertJsonPath('comparison.status', 'available')
        ->assertJsonPath('comparison.routing_context.depart_at', now()->toIso8601String());
    expect($departures)->toBe([now()->toIso8601String(), now()->toIso8601String()]);
    Http::assertSentCount(2);
});

it('rejects malformed raw baseline metrics without losing the candidate', function () {
    config(['services.tomtom.key' => 'synthetic-comparison-test-only']);
    Http::fake(function (Request $request) {
        $body = p53ComparisonBody($request, [2, 0, 1], 12000, 1200);
        if ($request['computeBestOrder'] === 'false') {
            $body['routes'][0]['summary']['lengthInMeters'] = '12000';
        }

        return Http::response($body);
    });
    $this->actingAs(p53User())->postJson(route('route-planning.traffic-route'), p53ComparisonPayload(p53Reservation()))
        ->assertSuccessful()->assertJsonPath('comparison.status', 'unavailable')->assertJsonPath('comparison.difference', null)
        ->assertJsonPath('provider', 'TomTom');
    Http::assertSentCount(2);
});

beforeEach(function () {
    Http::preventStrayRequests();
});

function p53User(): User
{
    return User::factory()->create(['role' => 'fleet_manager']);
}

/** @return array{0: Vehicle, 1: Driver} */
function p53Assignment(array $vehicleAttributes = [], array $driverAttributes = []): array
{
    $vehicle = Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('P53-####'),
        'vehicle_type' => 'Service Vehicle',
        'lto_vehicle_category' => 'M1',
        'department' => 'Operations',
        'brand' => 'Phase',
        'model' => 'Five A Three',
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 35,
        'current_odometer' => 100,
        'transmission_type' => 'automatic',
        'insurance_expiry' => now()->addYear()->toDateString(),
        'status' => 'Available',
        'readiness_status' => 'ready',
    ], $vehicleAttributes));

    $driver = Driver::query()->create(array_merge([
        'first_name' => 'Phase',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('P53-LIC-####'),
        'license_class' => 'Professional',
        'clutch_authorization' => 'AT',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'email' => fake()->unique()->safeEmail(),
        'assigned_vehicle_id' => $vehicle->id,
        'status' => 'Available',
    ], $driverAttributes));

    $driver->licenseCodes()->create(['code' => 'B']);
    DriverVehicleQualification::query()->create([
        'driver_id' => $driver->id,
        'vehicle_type' => $vehicle->vehicle_type,
    ]);

    return [$vehicle, $driver];
}

function p53Reservation(array $attributes = []): Reservation
{
    [$vehicle, $driver] = p53Assignment();

    return Reservation::query()->create(array_merge([
        'reservation_number' => fake()->unique()->bothify('P53-RSV-####'),
        'patient_name' => 'Emergency Patient',
        'request_type' => 'emergency_response',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'pickup_location' => 'Emergency Department',
        'destination' => 'Referral Hospital',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00:00',
        'priority' => 'emergency',
        'status' => 'Approved',
    ], $attributes));
}

function p53Route(Reservation $reservation, array $attributes = []): RoutePlan
{
    return RoutePlan::query()->create(array_merge([
        'route_number' => fake()->unique()->bothify('P53-ROUTE-####'),
        'reservation_id' => $reservation->id,
        'origin' => 'Emergency Department',
        'destination' => 'Referral Hospital',
        'priority' => $reservation->priority,
        'department' => 'Emergency',
        'status' => 'Planned',
        'departure_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00',
    ], $attributes));
}

function p53UpdatePayload(array $attributes = []): array
{
    return array_merge([
        'origin' => 'Emergency Department',
        'origin_latitude' => 14.5995,
        'origin_longitude' => 120.9842,
        'destination' => 'Referral Hospital',
        'destination_latitude' => 14.6091,
        'destination_longitude' => 121.0223,
        'department' => 'Emergency',
        'status' => 'Planned',
        'estimated_distance' => 12.5,
        'estimated_time' => 28,
        'optimization_strategy' => 'TomTom Traffic-Aware Fastest',
        'optimization_score' => 94,
        'optimization_applied' => true,
        'stops' => [],
    ], $attributes);
}

function p53OptimizationProof(User $user, Reservation $reservation, array $payload): string
{
    return Crypt::encryptString(json_encode([
        'user_id' => $user->id,
        'reservation_id' => $reservation->id,
        'issued_at' => now()->timestamp,
        'origin_latitude' => $payload['origin_latitude'],
        'origin_longitude' => $payload['origin_longitude'],
        'destination_latitude' => $payload['destination_latitude'],
        'destination_longitude' => $payload['destination_longitude'],
        'coordinates' => [
            [
                'latitude' => $payload['origin_latitude'],
                'longitude' => $payload['origin_longitude'],
            ],
            [
                'latitude' => $payload['destination_latitude'],
                'longitude' => $payload['destination_longitude'],
            ],
        ],
        'estimated_distance' => $payload['estimated_distance'],
        'estimated_time' => $payload['estimated_time'],
        'optimization_strategy' => $payload['optimization_strategy'],
        'provider' => 'tomtom',
    ], JSON_THROW_ON_ERROR));
}

function p53TomTomResult(): array
{
    return [
        'provider' => 'tomtom',
        'distance_meters' => 12500,
        'travel_time_seconds' => 1680,
        'traffic_delay_seconds' => 120,
        'traffic_length_meters' => 500,
        'no_traffic_travel_time_seconds' => 1560,
        'historic_traffic_travel_time_seconds' => 1620,
        'live_traffic_travel_time_seconds' => 1680,
        'departure_time' => now()->toIso8601String(),
        'arrival_time' => now()->addMinutes(28)->toIso8601String(),
        'optimized_waypoints' => [],
        'instructions' => [],
        'points' => [
            ['latitude' => 14.5995, 'longitude' => 120.9842],
            ['latitude' => 14.6091, 'longitude' => 121.0223],
        ],
    ];
}

it('centralizes canonical priority normalization and emergency detection', function () {
    expect(Reservation::canonicalPriority('emergency'))->toBe('emergency')
        ->and(Reservation::canonicalPriority('Emergency'))->toBe('emergency')
        ->and(Reservation::canonicalPriority('High'))->toBe('urgent')
        ->and(Reservation::canonicalPriority('Normal'))->toBe('routine')
        ->and(Reservation::canonicalPriority('Low'))->toBe('scheduled')
        ->and(Reservation::isEmergencyPriority('emergency'))->toBeTrue()
        ->and(Reservation::isEmergencyPriority('Emergency'))->toBeTrue()
        ->and(Reservation::isEmergencyPriority('routine'))->toBeFalse()
        ->and(Reservation::isEmergencyRequestType('emergency_response'))->toBeTrue()
        ->and(Reservation::isEmergencyRequestType('Emergency Transfer'))->toBeTrue();
});

it('copies canonical reservation priority on create and ignores crafted route priority', function () {
    $reservation = p53Reservation([
        'request_type' => 'staff_transport',
        'patient_name' => null,
        'priority' => 'High',
    ]);

    $response = $this->actingAs(p53User())->postJson(route('route-planning.store'), [
        'reservation_id' => $reservation->id,
        'department' => 'Operations',
        'priority' => 'Emergency',
    ]);

    $response->assertCreated()->assertJsonPath('routePlan.priority', 'urgent');
    $this->assertDatabaseHas('route_plans', [
        'reservation_id' => $reservation->id,
        'priority' => 'urgent',
        'status' => 'Draft',
    ]);
});

it('re-synchronizes priority from the reservation on edit and exposes its canonical label', function () {
    $reservation = p53Reservation([
        'request_type' => 'staff_transport',
        'patient_name' => null,
        'priority' => 'Normal',
    ]);
    $routePlan = p53Route($reservation, [
        'priority' => 'Emergency',
        'status' => 'Draft',
    ]);

    $this->actingAs(p53User())
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload([
            'status' => 'Planned',
            'priority' => 'Emergency',
            'optimization_applied' => false,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('routePlan.priority', 'routine')
        ->assertJsonPath('routePlan.reservation.priority_label', 'Routine');
});

it('duplicates with the target reservation priority instead of the source route priority', function () {
    $sourceReservation = p53Reservation(['priority' => 'emergency']);
    $sourceRoute = p53Route($sourceReservation, ['priority' => 'Emergency']);
    $targetReservation = p53Reservation([
        'request_type' => 'administrative_trip',
        'patient_name' => null,
        'priority' => 'Low',
    ]);

    $this->actingAs(p53User())
        ->postJson(route('route-planning.duplicate', $sourceRoute), [
            'reservation_id' => $targetReservation->id,
        ])
        ->assertCreated()
        ->assertJsonPath('routePlan.priority', 'scheduled');
});

it('automatically makes a planned emergency route ready only after a valid optimization is persisted', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation);
    $user = p53User();
    $vehicleId = $reservation->vehicle_id;
    $driverId = $reservation->driver_id;
    $tomTom = Mockery::mock(TomTomRoutingService::class);
    $tomTom->shouldReceive('calculateRoute')->once()->andReturn(p53TomTomResult());
    $this->app->instance(TomTomRoutingService::class, $tomTom);

    $optimization = $this->actingAs($user)
        ->postJson(route('route-planning.traffic-route'), [
            'reservation_id' => $reservation->id,
            'coordinates' => [
                ['latitude' => 14.5995, 'longitude' => 120.9842],
                ['latitude' => 14.6091, 'longitude' => 121.0223],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($user)
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload([
            'optimization_proof' => $optimization->json('optimization_proof'),
        ]))
        ->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Ready For Dispatch');

    expect($routePlan->fresh()->status)->toBe('Ready For Dispatch')
        ->and($reservation->fresh()->vehicle_id)->toBe($vehicleId)
        ->and($reservation->fresh()->driver_id)->toBe($driverId)
        ->and(Dispatch::query()->where('reservation_id', $reservation->id)->exists())->toBeFalse();
});

it('promotes a draft emergency route through its valid planned transition when trusted optimization is applied', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation, ['status' => 'Draft']);
    $user = p53User();
    $tomTom = Mockery::mock(TomTomRoutingService::class);
    $tomTom->shouldReceive('calculateRoute')->once()->andReturn(p53TomTomResult());
    $this->app->instance(TomTomRoutingService::class, $tomTom);

    $optimization = $this->actingAs($user)
        ->postJson(route('route-planning.traffic-route'), [
            'reservation_id' => $reservation->id,
            'coordinates' => [
                ['latitude' => 14.5995, 'longitude' => 120.9842],
                ['latitude' => 14.6091, 'longitude' => 121.0223],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($user)
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload([
            'optimization_proof' => $optimization->json('optimization_proof'),
        ]))
        ->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Ready For Dispatch');

    expect($routePlan->fresh()->status)->toBe('Ready For Dispatch');
});

it('uses canonical reservation priority for emergency readiness when request type differs', function () {
    $reservation = p53Reservation([
        'request_type' => 'patient_transfer',
        'priority' => 'emergency',
    ]);
    $routePlan = p53Route($reservation, ['status' => 'Draft']);
    $user = p53User();
    $tomTom = Mockery::mock(TomTomRoutingService::class);
    $tomTom->shouldReceive('calculateRoute')->once()->andReturn(p53TomTomResult());
    $this->app->instance(TomTomRoutingService::class, $tomTom);

    $this->actingAs($user)
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload([
            'optimization_applied' => false,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Planned')
        ->assertJsonPath('routePlan.reservation.is_emergency', true);

    $optimization = $this->actingAs($user)
        ->postJson(route('route-planning.traffic-route'), [
            'reservation_id' => $reservation->id,
            'coordinates' => [
                ['latitude' => 14.5995, 'longitude' => 120.9842],
                ['latitude' => 14.6091, 'longitude' => 121.0223],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($user)
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload([
            'optimization_proof' => $optimization->json('optimization_proof'),
        ]))
        ->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Ready For Dispatch')
        ->assertJsonPath('routePlan.reservation.is_emergency', true);

    expect($routePlan->fresh()->status)->toBe('Ready For Dispatch');
});

it('does not infer emergency readiness from request type when canonical priority is routine', function () {
    $reservation = p53Reservation([
        'request_type' => 'emergency_response',
        'priority' => 'routine',
    ]);
    $routePlan = p53Route($reservation, ['status' => 'Draft']);

    $this->actingAs(p53User())
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload())
        ->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Planned')
        ->assertJsonPath('routePlan.reservation.is_emergency', false);

    expect($routePlan->fresh()->status)->toBe('Planned');
});

it('does not let an OSRM fallback authorize draft emergency promotion', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation, ['status' => 'Draft']);

    $this->actingAs(p53User())
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload([
            'optimization_strategy' => 'OSRM Fastest Route (Fallback)',
            'optimization_proof' => null,
        ]))
        ->assertUnprocessable();

    expect($routePlan->fresh()->status)->toBe('Draft')
        ->and($routePlan->fresh()->estimated_distance)->toBeNull();
});

it('does not mark an emergency route ready when optimization is calculated but not persisted', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation);
    $tomTom = Mockery::mock(TomTomRoutingService::class);
    $tomTom->shouldReceive('calculateRoute')->once()->andReturn(p53TomTomResult());
    $this->app->instance(TomTomRoutingService::class, $tomTom);

    $this->actingAs(p53User())
        ->postJson(route('route-planning.traffic-route'), [
            'reservation_id' => $reservation->id,
            'coordinates' => [
                ['latitude' => 14.5995, 'longitude' => 120.9842],
                ['latitude' => 14.6091, 'longitude' => 121.0223],
            ],
        ])
        ->assertSuccessful();

    expect($routePlan->fresh()->status)->toBe('Planned');
});

it('rejects incomplete or unsafe emergency readiness without partially mutating the route', function (string $case) {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation);
    $user = p53User();
    $payload = p53UpdatePayload();
    $payload['optimization_proof'] = p53OptimizationProof($user, $reservation, $payload);

    match ($case) {
        'distance' => $payload['estimated_distance'] = null,
        'time' => $payload['estimated_time'] = null,
        'strategy' => $payload['optimization_strategy'] = null,
        'coordinates' => $payload['origin_latitude'] = null,
        'vehicle' => $reservation->update(['vehicle_id' => null]),
        'driver' => $reservation->update(['driver_id' => null]),
        'pair' => $reservation->driver->update(['status' => 'Off Duty']),
        'approval' => $reservation->update(['status' => 'Pending']),
    };

    $this->actingAs($user)
        ->postJson(route('route-planning.transition', $routePlan), $payload)
        ->assertUnprocessable();

    expect($routePlan->fresh()->status)->toBe('Planned')
        ->and($routePlan->fresh()->estimated_distance)->toBeNull()
        ->and(Dispatch::query()->where('reservation_id', $reservation->id)->exists())->toBeFalse();
})->with(['distance', 'time', 'strategy', 'coordinates', 'vehicle', 'driver', 'pair', 'approval']);

it('rejects a forged applied marker and fabricated route metrics', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation, ['priority' => 'Normal']);

    $this->actingAs(p53User())
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload())
        ->assertUnprocessable()
        ->assertJsonPath('success', false);

    expect($routePlan->fresh()->status)->toBe('Planned')
        ->and($routePlan->fresh()->estimated_distance)->toBeNull()
        ->and($routePlan->fresh()->priority)->toBe('Normal');
});

it('rejects a valid server proof when submitted stops use a different order', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation);
    $user = p53User();
    $tomTom = Mockery::mock(TomTomRoutingService::class);
    $tomTom->shouldReceive('calculateRoute')->once()->andReturn(array_replace(p53TomTomResult(), [
        'optimized_waypoints' => [
            ['providedIndex' => 0, 'optimizedIndex' => 0],
            ['providedIndex' => 1, 'optimizedIndex' => 1],
        ],
    ]));
    $this->app->instance(TomTomRoutingService::class, $tomTom);
    $stopOne = ['latitude' => 14.6010, 'longitude' => 120.9900];
    $stopTwo = ['latitude' => 14.6050, 'longitude' => 121.0100];

    $optimization = $this->actingAs($user)
        ->postJson(route('route-planning.traffic-route'), [
            'reservation_id' => $reservation->id,
            'coordinates' => [
                ['latitude' => 14.5995, 'longitude' => 120.9842],
                $stopOne,
                $stopTwo,
                ['latitude' => 14.6091, 'longitude' => 121.0223],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($user)
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload([
            'optimization_proof' => $optimization->json('optimization_proof'),
            'optimization_strategy' => 'TomTom Traffic-Aware Optimized',
            'stops' => [
                ['location' => 'Stop Two', ...$stopTwo],
                ['location' => 'Stop One', ...$stopOne],
            ],
        ]))
        ->assertUnprocessable();

    expect($routePlan->fresh()->status)->toBe('Planned')
        ->and($routePlan->stops()->count())->toBe(0);
});

it('keeps emergency readiness server-owned instead of accepting a manual status selection', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation);

    $this->actingAs(p53User())
        ->putJson(route('route-planning.update', $routePlan), p53UpdatePayload([
            'status' => 'Ready For Dispatch',
        ]))
        ->assertUnprocessable();

    expect($routePlan->fresh()->status)->toBe('Planned');
});

it('protects dispatch-locked, archived, and completed emergency routes from readiness regression', function (string $state) {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation, ['status' => $state === 'dispatch' ? 'Planned' : $state]);

    if ($state === 'dispatch') {
        Dispatch::query()->create([
            'dispatch_number' => fake()->unique()->bothify('P53-DSP-####'),
            'reservation_id' => $reservation->id,
            'dispatch_date' => now()->addDay()->toDateString(),
            'departure_time' => '09:00:00',
            'trip_status' => 'Pending',
        ]);
    }

    $this->actingAs(p53User())
        ->putJson(route('route-planning.update', $routePlan), p53UpdatePayload([
            'status' => $state === 'dispatch' ? 'Planned' : 'Ready For Dispatch',
        ]))
        ->assertUnprocessable();

    expect($routePlan->fresh()->status)->toBe($state === 'dispatch' ? 'Planned' : $state);
})->with(['dispatch', 'Archived', 'Completed']);

it('keeps an already ready emergency route stable and does not create a dispatch', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation, [
        'status' => 'Ready For Dispatch',
        'estimated_distance' => 12.5,
        'estimated_time' => 28,
        'optimization_strategy' => 'Fastest',
    ]);

    $this->actingAs(p53User())
        ->putJson(route('route-planning.update', $routePlan), p53UpdatePayload([
            'status' => 'Ready For Dispatch',
        ]))
        ->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Ready For Dispatch');

    expect(Dispatch::query()->where('reservation_id', $reservation->id)->exists())->toBeFalse();
});

it('preserves draft planning and non-emergency route lifecycle behavior', function () {
    $draftEmergency = p53Route(p53Reservation(), ['status' => 'Draft']);
    $normalReservation = p53Reservation([
        'request_type' => 'administrative_trip',
        'patient_name' => null,
        'priority' => 'routine',
    ]);
    $normalRoute = p53Route($normalReservation);

    $this->actingAs(p53User())
        ->postJson(route('route-planning.transition', $draftEmergency), p53UpdatePayload([
            'optimization_applied' => false,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Planned');

    $this->actingAs(p53User())
        ->putJson(route('route-planning.update', $normalRoute), p53UpdatePayload())
        ->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Planned');
});

it('preserves a valid waypoint permutation through proof save and reopen', function (array $mapping, array $order, bool $repeatedLabels, bool $repeatedCoordinates) {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation);
    $user = p53User();
    $stops = [];
    foreach (array_keys($order) as $index) {
        $stops[] = [
            'location' => $repeatedLabels ? 'Repeated synthetic stop' : 'Synthetic stop '.$index,
            'latitude' => $repeatedCoordinates ? 14.601 : round(14.601 + $index / 1000, 6),
            'longitude' => $repeatedCoordinates ? 121.001 : round(121.001 + $index / 1000, 6),
        ];
    }
    $coordinates = [
        ['latitude' => 14.5995, 'longitude' => 120.9842],
        ...array_map(fn (array $stop): array => array_intersect_key($stop, array_flip(['latitude', 'longitude'])), $stops),
        ['latitude' => 14.6091, 'longitude' => 121.0223],
    ];
    $tomTom = Mockery::mock(TomTomRoutingService::class);
    $tomTom->shouldReceive('calculateRoute')->once()->with($coordinates, null)->andReturn(array_replace(p53TomTomResult(), [
        'optimized_waypoints' => $mapping,
    ]));
    $this->app->instance(TomTomRoutingService::class, $tomTom);

    $response = $this->actingAs($user)->postJson(route('route-planning.traffic-route'), [
        'reservation_id' => $reservation->id,
        'coordinates' => $coordinates,
    ])->assertSuccessful();

    $orderedStops = array_map(fn (int $index): array => $stops[$index], $order);
    $proof = json_decode(Crypt::decryptString($response->json('optimization_proof')), true, flags: JSON_THROW_ON_ERROR);
    expect($proof['coordinates'])->toBe([
        $coordinates[0],
        ...array_map(fn (int $index): array => $coordinates[$index + 1], $order),
        $coordinates[array_key_last($coordinates)],
    ])->and($routePlan->fresh()->status)->toBe('Planned')
        ->and($routePlan->stops()->count())->toBe(0);

    $this->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload([
        'optimization_proof' => $response->json('optimization_proof'),
        'optimization_strategy' => $proof['optimization_strategy'],
        'stops' => $orderedStops,
    ]))->assertSuccessful()->assertJsonPath('routePlan.status', 'Ready For Dispatch');

    $reopened = $this->getJson(route('route-planning.show', $routePlan))->assertSuccessful();
    $persisted = $routePlan->fresh()->stops->map(fn ($stop): array => [
        'location' => $stop->location,
        'latitude' => (float) $stop->latitude,
        'longitude' => (float) $stop->longitude,
    ])->all();
    expect($persisted)->toBe($orderedStops)
        ->and($routePlan->fresh()->stops->pluck('stop_order')->all())->toBe($order === [] ? [] : range(1, count($order)))
        ->and(array_column($reopened->json('routePlan.stops'), 'location'))->toBe(array_column($orderedStops, 'location'));
})->with([
    'identity' => [[['providedIndex' => 0, 'optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 1]], [0, 1], false, false],
    'reversal' => [[['providedIndex' => 0, 'optimizedIndex' => 1], ['providedIndex' => 1, 'optimizedIndex' => 0]], [1, 0], false, false],
    'cycle with shuffled metadata' => [[['providedIndex' => 1, 'optimizedIndex' => 2], ['providedIndex' => 2, 'optimizedIndex' => 0], ['providedIndex' => 0, 'optimizedIndex' => 1]], [2, 0, 1], false, false],
    'repeated labels' => [[['providedIndex' => 0, 'optimizedIndex' => 1], ['providedIndex' => 1, 'optimizedIndex' => 0]], [1, 0], true, false],
    'repeated coordinates' => [[['providedIndex' => 0, 'optimizedIndex' => 1], ['providedIndex' => 1, 'optimizedIndex' => 0]], [1, 0], false, true],
    'identical repeated stops' => [[['providedIndex' => 0, 'optimizedIndex' => 1], ['providedIndex' => 1, 'optimizedIndex' => 0]], [1, 0], true, true],
    'direct route' => [[], [], false, false],
    'one stop without metadata' => [[], [0], false, false],
    'one stop identity' => [[['providedIndex' => 0, 'optimizedIndex' => 0]], [0], false, false],
]);

it('rejects malformed waypoint metadata without issuing proof or changing route history', function (mixed $mapping, int $stopCount) {
    $routePlan = p53Route(p53Reservation());
    $routePlan->stops()->create(['stop_order' => 1, 'location' => 'Existing synthetic stop', 'latitude' => 14.6, 'longitude' => 121.0]);
    $before = $routePlan->fresh()->toArray();
    $auditCount = AuditLog::count();
    $coordinates = [['latitude' => 14.5995, 'longitude' => 120.9842]];
    for ($index = 0; $index < $stopCount; $index++) {
        $coordinates[] = ['latitude' => 14.601 + $index / 1000, 'longitude' => 121.001 + $index / 1000];
    }
    $coordinates[] = ['latitude' => 14.6091, 'longitude' => 121.0223];
    $tomTom = Mockery::mock(TomTomRoutingService::class);
    $tomTom->shouldReceive('calculateRoute')->once()->andReturn(array_replace(p53TomTomResult(), ['optimized_waypoints' => $mapping]));
    $this->app->instance(TomTomRoutingService::class, $tomTom);

    $this->actingAs(p53User())->postJson(route('route-planning.traffic-route'), [
        'coordinates' => $coordinates,
        'reservation_id' => $routePlan->reservation_id,
    ])->assertStatus(502)->assertJsonPath('success', false)->assertJsonMissingPath('optimization_proof');

    expect($routePlan->fresh()->toArray())->toBe($before)
        ->and($routePlan->stops()->pluck('location')->all())->toBe(['Existing synthetic stop'])
        ->and(AuditLog::count())->toBe($auditCount);
})->with([
    'missing multi-stop mapping' => [[], 2],
    'incorrect count' => [[['providedIndex' => 0, 'optimizedIndex' => 0]], 2],
    'duplicate source' => [[['providedIndex' => 0, 'optimizedIndex' => 0], ['providedIndex' => 0, 'optimizedIndex' => 1]], 2],
    'duplicate destination' => [[['providedIndex' => 0, 'optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 0]], 2],
    'missing source' => [[['optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'missing destination' => [[['providedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'negative source' => [[['providedIndex' => -1, 'optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'large source' => [[['providedIndex' => 2, 'optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'negative destination' => [[['providedIndex' => 0, 'optimizedIndex' => -1], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'large destination' => [[['providedIndex' => 0, 'optimizedIndex' => 2], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'fractional source' => [[['providedIndex' => 0.5, 'optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'fractional destination' => [[['providedIndex' => 0, 'optimizedIndex' => 0.5], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'numeric string' => [[['providedIndex' => '0', 'optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'string destination' => [[['providedIndex' => 0, 'optimizedIndex' => '0'], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'boolean' => [[['providedIndex' => false, 'optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'boolean destination' => [[['providedIndex' => 0, 'optimizedIndex' => false], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'null index' => [[['providedIndex' => null, 'optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'null destination' => [[['providedIndex' => 0, 'optimizedIndex' => null], ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'non-object entry' => [[null, ['providedIndex' => 1, 'optimizedIndex' => 1]], 2],
    'null metadata' => [null, 2],
    'non-list metadata' => [['entry' => ['providedIndex' => 0, 'optimizedIndex' => 0]], 1],
    'one stop invalid source' => [[['providedIndex' => 1, 'optimizedIndex' => 0]], 1],
    'one stop invalid destination' => [[['providedIndex' => 0, 'optimizedIndex' => 1]], 1],
    'direct route unexpected metadata' => [[['providedIndex' => 0, 'optimizedIndex' => 0]], 0],
]);
