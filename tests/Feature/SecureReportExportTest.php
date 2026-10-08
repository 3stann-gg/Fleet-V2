<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

function secureExportClient(TestCase $test): TestCase
{
    return $test
        ->withSession(['_token' => 'secure-export-test-token'])
        ->withHeader('X-CSRF-TOKEN', 'secure-export-test-token');
}

function secureExportPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'context' => 'reports',
        'format' => 'pdf',
        'report_type' => 'overview',
        'protected_file' => false,
        'current_password' => 'password',
        'filters' => ['date_range' => 'last30'],
    ], $overrides);
}

it('denies unauthenticated secure export requests', function () {
    secureExportClient($this)->postJson(route('reports.export.authorize'), secureExportPayload())
        ->assertUnauthorized();
});

it('denies roles without reports export permission', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    secureExportClient($this)->actingAs($user)
        ->postJson(route('reports.export.authorize'), secureExportPayload())
        ->assertForbidden();
})->with(['driver', 'department_head', 'it_admin']);

it('allows finalized authorized roles to confirm permitted exports', function (string $role, string $reportType) {
    $user = User::factory()->create(['role' => $role]);

    $response = secureExportClient($this)->actingAs($user)
        ->postJson(route('reports.export.authorize'), secureExportPayload(['report_type' => $reportType]));

    $response
        ->assertOk()
        ->assertJsonPath('authorized', true)
        ->assertJsonPath('expires_in', 300);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    ['fleet_manager', 'overview'],
    ['dispatcher', 'trips'],
    ['maintenance', 'maintenance'],
    ['finance', 'fuel'],
]);

it('rejects an incorrect current password without an audit record', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    secureExportClient($this)->actingAs($user)
        ->postJson(route('reports.export.authorize'), secureExportPayload([
            'current_password' => 'incorrect-password',
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.current_password.0', 'Password confirmation failed.');

    expect(AuditLog::query()->count())->toBe(0);
});

it('does not accept another users password or a manipulated user id', function () {
    $user = User::factory()->create([
        'role' => 'fleet_manager',
        'password' => Hash::make('own-password'),
    ]);
    $other = User::factory()->create([
        'role' => 'fleet_manager',
        'password' => Hash::make('other-password'),
    ]);

    secureExportClient($this)->actingAs($user)
        ->postJson(route('reports.export.authorize'), secureExportPayload([
            'current_password' => 'other-password',
            'user_id' => $other->id,
        ]))
        ->assertUnprocessable();
});

it('denies inactive accounts', function () {
    $user = User::factory()->create(['role' => 'fleet_manager', 'status' => 'inactive']);

    secureExportClient($this)->actingAs($user)
        ->postJson(route('reports.export.authorize'), secureExportPayload())
        ->assertForbidden();
});

it('requires a current password when no recent confirmation exists', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    secureExportClient($this)->actingAs($user)
        ->postJson(route('reports.export.authorize'), secureExportPayload(['current_password' => null]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.current_password.0', 'Password confirmation failed.');
});

it('reuses confirmation for five minutes and rejects an expired timestamp', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    secureExportClient($this)->actingAs($user)
        ->postJson(route('reports.export.authorize'), secureExportPayload())
        ->assertOk();

    secureExportClient($this)->postJson(route('reports.export.authorize'), secureExportPayload(['current_password' => null]))
        ->assertOk();

    $this->withSession(['secure_report_export_confirmed_at' => now()->subSeconds(301)->timestamp])
        ->postJson(route('reports.export.authorize'), secureExportPayload(['current_password' => null]))
        ->assertUnprocessable();
});

it('rate limits repeated failed password confirmation attempts', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    foreach (range(1, 5) as $attempt) {
        secureExportClient($this)->actingAs($user)
            ->postJson(route('reports.export.authorize'), secureExportPayload([
                'current_password' => "wrong-{$attempt}",
            ]))
            ->assertUnprocessable();
    }

    secureExportClient($this)->actingAs($user)
        ->postJson(route('reports.export.authorize'), secureExportPayload(['current_password' => 'still-wrong']))
        ->assertTooManyRequests();
});

it('rejects report types outside the authorized role scope', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);

    secureExportClient($this)->actingAs($dispatcher)
        ->postJson(route('reports.export.authorize'), secureExportPayload(['report_type' => 'maintenance']))
        ->assertForbidden();
});

it('preserves stricter module access for non reports exports', function () {
    $finance = User::factory()->create(['role' => 'finance']);

    secureExportClient($this)->actingAs($finance)
        ->postJson(route('reports.export.authorize'), secureExportPayload([
            'context' => 'dispatch',
            'report_type' => null,
        ]))
        ->assertForbidden();
});

it('rejects file-password mismatch before export', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    secureExportClient($this)->actingAs($user)
        ->postJson(route('reports.export.authorize'), secureExportPayload([
            'protected_file' => true,
            'export_password' => 'separate-secret',
            'export_password_confirmation' => 'different-secret',
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.export_password.0', 'Export file passwords do not match.');
});

it('honestly rejects protected csv when encrypted zip is unavailable', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    secureExportClient($this)->actingAs($user)
        ->postJson(route('reports.export.authorize'), secureExportPayload([
            'format' => 'csv',
            'protected_file' => true,
            'export_password' => 'separate-secret',
            'export_password_confirmation' => 'separate-secret',
        ]))
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.protected_file.0',
            'Protected export is not available for this format on the current server configuration.'
        );
});

it('authorizes native jsPDF protection with a separate file password', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    $response = secureExportClient($this)->actingAs($user)
        ->postJson(route('reports.export.authorize'), secureExportPayload([
            'format' => 'pdf',
            'protected_file' => true,
            'export_password' => 'separate-secret',
            'export_password_confirmation' => 'separate-secret',
        ]));

    $response->assertOk()
        ->assertJsonMissing(['export_password' => 'separate-secret']);

    $audit = AuditLog::query()->latest('id')->firstOrFail();

    expect($audit->new_values['protected_file'])->toBeTrue()
        ->and(json_encode($audit->toArray()))->not->toContain('separate-secret');
});

it('logs successful authorization without account or export passwords', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    secureExportClient($this)->actingAs($user)
        ->postJson(route('reports.export.authorize'), secureExportPayload([
            'filters' => [
                'date_range' => 'custom',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-08',
                'department' => 'Emergency',
            ],
        ]))
        ->assertOk();

    $audit = AuditLog::query()->latest('id')->firstOrFail();
    $encoded = json_encode($audit->toArray());

    expect($audit->action)->toBe('Exported')
        ->and($audit->new_values['format'])->toBe('pdf')
        ->and($audit->new_values['report_type'])->toBe('overview')
        ->and($audit->new_values['filters']['department'])->toBe('Emergency')
        ->and($audit->new_values['protected_file'])->toBeFalse()
        ->and($encoded)->not->toContain('password')
        ->and($encoded)->not->toContain('separate-secret');
});

it('does not support password-bearing get requests', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    secureExportClient($this)->actingAs($user)
        ->getJson(route('reports.export.authorize', ['current_password' => 'password']))
        ->assertMethodNotAllowed();
});

it('requires confirmation before a direct csv download', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    secureExportClient($this)->actingAs($user)
        ->postJson(route('reports.export.csv'), secureExportPayload([
            'format' => 'csv',
            'current_password' => null,
        ]))
        ->assertUnprocessable();
});

it('downloads a scoped filtered csv and logs its record count', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $now = now();
    $selectedId = DB::table('vehicles')->insertGetId([
        'plate_number' => 'SAFE-101', 'vehicle_type' => 'Ambulance',
        'brand' => 'Selected', 'model' => 'Unit',
        'capacity' => 4, 'fuel_type' => 'Diesel', 'status' => 'Available',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('vehicles')->insert([
        'plate_number' => 'HIDE-202', 'vehicle_type' => 'SUV',
        'brand' => 'Excluded', 'model' => 'Unit',
        'capacity' => 4, 'fuel_type' => 'Gasoline', 'status' => 'Available',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $response = secureExportClient($this)->actingAs($user)
        ->post(route('reports.export.csv'), secureExportPayload([
            'format' => 'csv',
            'report_type' => 'utilization',
            'filters' => ['date_range' => 'last30', 'vehicle_id' => $selectedId],
        ]));

    $response->assertOk()
        ->assertDownload('fleet_utilization_report_'.now()->format('Y-m-d').'.csv');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $content = $response->streamedContent();

    expect($content)->toContain('SAFE-101')
        ->and($content)->not->toContain('HIDE-202')
        ->and(AuditLog::query()->latest('id')->firstOrFail()->new_values['record_count'])->toBe(1);
});

it('keeps csrf middleware on both secure export routes', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array($route->getName(), [
            'reports.export.authorize',
            'reports.export.csv',
        ], true));

    expect($routes)->toHaveCount(2);

    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('web');
    }
});
