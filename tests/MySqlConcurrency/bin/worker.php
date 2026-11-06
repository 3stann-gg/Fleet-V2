<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\MySqlConcurrency\Support\BusinessConcurrencyWorker;
use Tests\MySqlConcurrency\Support\HarnessEnvironment;
use Tests\MySqlConcurrency\Support\HarnessSafetyPolicy;
use Tests\MySqlConcurrency\Support\IsolatedMySqlEnvironment;

$projectRoot = dirname(__DIR__, 3);

require $projectRoot.'/vendor/autoload.php';

try {
    $action = $argv[1] ?? '';
    $encodedPayload = $argv[2] ?? '';
    $decodedPayload = base64_decode($encodedPayload, true);
    $payload = is_string($decodedPayload)
        ? json_decode($decodedPayload, true, 512, JSON_THROW_ON_ERROR)
        : null;

    if ($action === '' || ! is_array($payload)) {
        throw new RuntimeException('A valid worker action and encoded payload are required.');
    }

    HarnessEnvironment::load($projectRoot);
    HarnessSafetyPolicy::assertConfiguration(HarnessEnvironment::values());

    foreach (HarnessEnvironment::processEnvironment() as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    $expectedLockName = HarnessSafetyPolicy::lockName(HarnessEnvironment::values()['instance_id']);
    HarnessSafetyPolicy::assertExternalLockHandoff(
        $expectedLockName,
        HarnessEnvironment::inheritedValue('MYSQL_CONCURRENCY_PARENT_LOCK_NAME'),
        HarnessEnvironment::inheritedValue('MYSQL_CONCURRENCY_PARENT_LOCK_OWNER')
    );

    $app = require $projectRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if ($app->configurationIsCached()) {
        throw new RuntimeException('Refusing to run a concurrency worker with cached Laravel configuration.');
    }

    IsolatedMySqlEnvironment::configureIndependentConnections();
    IsolatedMySqlEnvironment::assertSafe(IsolatedMySqlEnvironment::CONTROL_CONNECTION);
    IsolatedMySqlEnvironment::assertExternalLockOwner();
    IsolatedMySqlEnvironment::assertSafe();

    $result = (new BusinessConcurrencyWorker)->run($action, $payload);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Concurrency worker refused or failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
