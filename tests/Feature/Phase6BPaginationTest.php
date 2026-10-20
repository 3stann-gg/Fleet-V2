<?php

use App\Models\AuditLog;
use App\Models\SanitationLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function phase6bNumericPages(string $html, string $paginationId): array
{
    preg_match(
        '/<nav[^>]*id="'.preg_quote($paginationId, '/').'"[^>]*>.*?<\/nav>/s',
        $html,
        $navigation,
    );
    preg_match_all('/aria-label="Page (\d+)"/', $navigation[0] ?? '', $matches);

    return array_map('intval', $matches[1]);
}

function phase6bVehicle(): Vehicle
{
    return Vehicle::query()->create([
        'plate_number' => 'P6B-0001',
        'vehicle_type' => 'Service Vehicle',
        'lto_vehicle_category' => 'M1',
        'department' => 'Operations',
        'brand' => 'Pagination',
        'model' => 'Regression',
        'year_model' => 2026,
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 35,
        'current_odometer' => 100,
        'transmission_type' => 'automatic',
        'registration_expiry' => now()->addYear()->toDateString(),
        'insurance_expiry' => now()->addYear()->toDateString(),
        'status' => 'Available',
        'readiness_status' => 'ready',
    ]);
}

it('slides and clamps Audit Log pagination while preserving filters', function () {
    $user = User::factory()->create([
        'role' => 'it_admin',
        'status' => 'active',
    ]);
    $createdAt = now()->startOfDay()->addHour();
    $rows = [];

    for ($index = 1; $index <= 300; $index++) {
        $rows[] = [
            'module' => 'Pagination',
            'action' => 'Viewed',
            'description' => "phase6b audit record {$index}",
            'created_at' => $createdAt,
        ];
    }

    AuditLog::query()->insert($rows);

    $filters = [
        'search' => 'phase6b',
        'module' => 'Pagination',
        'action' => 'Viewed',
        'date' => $createdAt->toDateString(),
    ];
    $expectations = [
        1 => [1, 2, 3, 4, 5, 6, 7],
        2 => [2, 3, 4, 5, 6, 7, 8],
        5 => [5, 6, 7, 8, 9, 10, 11],
        10 => [6, 7, 8, 9, 10, 11, 12],
        12 => [6, 7, 8, 9, 10, 11, 12],
    ];

    foreach ($expectations as $page => $expectedPages) {
        $response = $this->actingAs($user)->get(route('audit-logs.index', [
            ...$filters,
            'page' => $page,
        ]));

        $response->assertOk();
        expect(phase6bNumericPages($response->getContent(), 'auditPagination'))
            ->toBe($expectedPages);

        $navigation = html_entity_decode($response->getContent());
        expect($navigation)
            ->toContain('search=phase6b')
            ->toContain('module=Pagination')
            ->toContain('action=Viewed')
            ->toContain('date='.$createdAt->toDateString());
    }

    $firstPage = $this->actingAs($user)->get(route('audit-logs.index', [...$filters, 'page' => 1]));
    $lastPage = $this->actingAs($user)->get(route('audit-logs.index', [...$filters, 'page' => 12]));

    $firstPage->assertSee('aria-label="Previous page" disabled', false);
    $lastPage->assertSee('aria-label="Next page" disabled', false);
    expect($lastPage->getContent())
        ->toMatch('/aria-label="Page 12"\s+aria-current="page"/');
});

it('uses the shared server paginator for sanitation and retains its filter', function () {
    $user = User::factory()->create([
        'role' => 'maintenance',
        'status' => 'active',
    ]);
    $vehicle = phase6bVehicle();

    for ($index = 1; $index <= 16; $index++) {
        SanitationLog::query()->create([
            'vehicle_id' => $vehicle->id,
            'reason' => 'routine_end_of_trip',
            'cleaning_type' => 'routine_cleaning',
            'status' => 'pending',
            'remarks' => "Phase 6B {$index}",
        ]);
    }

    $response = $this->actingAs($user)->get(route('sanitation.index', [
        'status' => 'pending',
        'page' => 2,
    ]));

    $response->assertOk();
    expect(phase6bNumericPages($response->getContent(), 'sanitationPagination'))
        ->toBe([1, 2]);
    expect(html_entity_decode($response->getContent()))->toContain('status=pending');
});

it('uses the shared JavaScript window in a paginated module', function () {
    $process = new Process([
        'node',
        '-e',
        'import(require("url").pathToFileURL(process.argv[1]).href).then(() => process.stdout.write(JSON.stringify(globalThis.FleetPagination.getVisiblePages(8, 10))));',
        public_path('assets/js/core/pagination.js'),
    ]);
    $process->mustRun();

    expect(json_decode($process->getOutput(), true))
        ->toBe([4, 5, 6, 7, 8, 9, 10]);

    $vehiclePagination = file_get_contents(public_path('assets/js/vehicle/vehicle-pagination.js'));
    expect($vehiclePagination)
        ->toContain('window.FleetPagination.getVisiblePages(')
        ->not->toContain('for (let page = 1; page <= totalPages; page += 1)');
});
