<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Tests\MySqlConcurrency\Support\HarnessEnvironment;
use Tests\MySqlConcurrency\Support\HarnessSafetyPolicy;
use Tests\MySqlConcurrency\Support\IsolatedMySqlEnvironment;
use Tests\MySqlConcurrency\Support\RouteProvenanceConcurrencyWorker;
use Tests\MySqlConcurrency\Support\RouteProvenanceConnection;

$projectRoot = dirname(__DIR__, 3);
require $projectRoot.'/vendor/autoload.php';

try {
    $action = $argv[1] ?? '';
    $decoded = base64_decode($argv[2] ?? '', true);
    $payload = is_string($decoded) ? json_decode($decoded, true, 64, JSON_THROW_ON_ERROR) : null;
    RouteProvenanceConcurrencyWorker::assertPayload($action, $payload);
    HarnessEnvironment::load($projectRoot);
    HarnessSafetyPolicy::assertConfiguration(HarnessEnvironment::values());
    foreach (HarnessEnvironment::routeProvenanceWorkerEnvironment() as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
    HarnessSafetyPolicy::assertExternalLockHandoff(HarnessSafetyPolicy::lockName(HarnessEnvironment::values()['instance_id']),
        HarnessEnvironment::inheritedValue('MYSQL_CONCURRENCY_PARENT_LOCK_NAME'), HarnessEnvironment::inheritedValue('MYSQL_CONCURRENCY_PARENT_LOCK_OWNER'));
    Connection::resolverFor('mysql', fn ($pdo, $database, $prefix, $config): RouteProvenanceConnection => new RouteProvenanceConnection($pdo, $database, $prefix, $config));
    $app = require $projectRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Cached configuration is forbidden.');
    }
    IsolatedMySqlEnvironment::configureIndependentConnections();
    IsolatedMySqlEnvironment::assertSafe(IsolatedMySqlEnvironment::CONTROL_CONNECTION);
    IsolatedMySqlEnvironment::assertExternalLockOwner();
    IsolatedMySqlEnvironment::assertSafe();
    fwrite(STDOUT, json_encode((new RouteProvenanceConcurrencyWorker)->run($action, $payload), JSON_THROW_ON_ERROR).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Route provenance worker refused or failed: '.HarnessEnvironment::sanitizeDiagnostic($exception->getMessage()).PHP_EOL);
    exit(1);
}
