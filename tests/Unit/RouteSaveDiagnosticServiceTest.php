<?php

use App\Services\RouteSaveDiagnosticService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class);

function routeDiagnosticFailure(string $state = '23000', int|string $code = 1062): QueryException
{
    $previous = new PDOException('seeded-private-password proof patient and location');
    $previous->errorInfo = [$state, $code, 'seeded-private-password'];

    return new QueryException('private-connection', 'insert private SQL ?', ['seeded-private-binding'], $previous);
}

beforeEach(function (): void {
    app()->instance('request', Request::create('/private-location', 'POST', [
        'optimization_proof' => 'private-proof', 'patient_name' => 'private-patient',
    ], server: ['HTTP_X_REQUEST_ID' => 'private-untrusted-request-id']));
});

it('logs only allowlisted scalar failure metadata and a server correlation ID', function (string $state, int|string $code, ?string $safeState, ?int $safeCode, string $category): void {
    Log::spy();
    RouteSaveDiagnosticService::failed('update', routeDiagnosticFailure($state, $code));
    Log::shouldHaveReceived('log')->once()->withArgs(function (string $level, string $event, array $context) use ($safeState, $safeCode, $category): bool {
        expect($level)->toBe('warning')->and($event)->toBe('fleet.route_save.failed')
            ->and(array_keys($context))->toBe(['operation', 'outcome', 'correlation_id', 'category', 'sqlstate', 'database_error'])
            ->and(Str::isUuid($context['correlation_id']))->toBeTrue()
            ->and($context)->toMatchArray(['operation' => 'update', 'outcome' => 'failed', 'sqlstate' => $safeState, 'database_error' => $safeCode, 'category' => $category])
            ->and(json_encode($context))->not->toContain('private', 'password', 'proof', 'patient', 'binding', 'exception');

        return true;
    });
})->with([
    ['23000', 1062, '23000', 1062, 'unique_constraint'], ['40001', '1213', '40001', 1213, 'deadlock'],
    ['HY000', 1205, 'HY000', 1205, 'lock_timeout'], ['23000', 1452, '23000', 1452, 'foreign_key'],
    ['HY000', 3819, 'HY000', 3819, 'check_constraint'], ['private-state', 'private-error', null, null, 'query_failure'],
]);

it('correlates retry recovery and terminal failure without trusting client headers', function (): void {
    $events = [];
    Log::shouldReceive('log')->times(3)->andReturnUsing(function (string $level, string $event, array $context) use (&$events): void {
        $events[] = ['level' => $level, 'event' => $event, 'context' => $context];
    });
    RouteSaveDiagnosticService::retryScheduled(1);
    RouteSaveDiagnosticService::recovered(2);
    RouteSaveDiagnosticService::failed('private-operation', new RuntimeException('private-proof'));
    expect(array_unique(array_column(array_column($events, 'context'), 'correlation_id')))->toHaveCount(1)
        ->and(array_column($events, 'event'))->toBe(['fleet.route_save.deadlock_retry', 'fleet.route_save.deadlock_recovered', 'fleet.route_save.failed'])
        ->and($events[2]['context'])->toMatchArray(['operation' => 'unknown', 'category' => 'operation_failure', 'sqlstate' => null, 'database_error' => null])
        ->and(json_encode($events))->not->toContain('private', 'HTTP_', 'exception');
});

it('contains log transport failures without throwing or reporting raw exceptions', function (): void {
    Log::shouldReceive('log')->times(3)->andThrow(new RuntimeException('private transport credentials'));
    RouteSaveDiagnosticService::retryScheduled(1);
    RouteSaveDiagnosticService::recovered(2);
    RouteSaveDiagnosticService::failed('update', routeDiagnosticFailure());
    expect(true)->toBeTrue();
});
