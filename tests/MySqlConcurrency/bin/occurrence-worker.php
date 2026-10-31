<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\MySqlConcurrency\Support\HarnessEnvironment;
use Tests\MySqlConcurrency\Support\HarnessSafetyPolicy;
use Tests\MySqlConcurrency\Support\IsolatedMySqlEnvironment;
use Tests\MySqlConcurrency\Support\OccurrenceConcurrencyWorker;

$projectRoot = dirname(__DIR__, 3);

require $projectRoot.'/vendor/autoload.php';

try {
    $action = $argv[1] ?? '';
    $decodedPayload = base64_decode($argv[2] ?? '', true);
    $payload = is_string($decodedPayload)
        ? json_decode($decodedPayload, true, 512, JSON_THROW_ON_ERROR)
        : null;

    if ($action === '' || ! is_array($payload)) {
        throw new RuntimeException('A valid occurrence worker action and encoded payload are required.');
    }

    HarnessEnvironment::load($projectRoot);
    HarnessSafetyPolicy::assertConfiguration(HarnessEnvironment::values());

    foreach (HarnessEnvironment::occurrenceWorkerEnvironment() as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    HarnessSafetyPolicy::assertExternalLockHandoff(
        HarnessSafetyPolicy::lockName(HarnessEnvironment::values()['instance_id']),
        HarnessEnvironment::inheritedValue('MYSQL_CONCURRENCY_PARENT_LOCK_NAME'),
        HarnessEnvironment::inheritedValue('MYSQL_CONCURRENCY_PARENT_LOCK_OWNER')
    );

    $app = require $projectRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if ($app->configurationIsCached()) {
        throw new RuntimeException('Refusing to run an occurrence worker with cached Laravel configuration.');
    }

    IsolatedMySqlEnvironment::configureIndependentConnections();
    IsolatedMySqlEnvironment::assertSafe(IsolatedMySqlEnvironment::CONTROL_CONNECTION);
    IsolatedMySqlEnvironment::assertExternalLockOwner();
    IsolatedMySqlEnvironment::assertSafe();
    HarnessSafetyPolicy::assertLifecycleFlags([
        'occurrences' => config('fleet-notifications.occurrences.enabled') === true,
        'after_commit' => config('fleet-notifications.reconciliation.after_commit_enabled') === true,
        'scheduled_recovery' => config('fleet-notifications.reconciliation.scheduled_recovery_enabled') === true,
        'historical' => config('fleet-notifications.reconciliation.historical_enabled') === true,
    ], true);

    fwrite(STDOUT, json_encode(
        (new OccurrenceConcurrencyWorker)->run($action, $payload),
        JSON_THROW_ON_ERROR
    ).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Occurrence worker refused or failed: '.HarnessEnvironment::sanitizeDiagnostic($exception->getMessage()).PHP_EOL);
    exit(1);
}
