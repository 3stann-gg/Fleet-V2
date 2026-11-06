<?php

use Tests\MySqlConcurrency\Support\HarnessEnvironment;
use Tests\MySqlConcurrency\Support\HarnessSafetyPolicy;

$projectRoot = dirname(__DIR__, 2);

require $projectRoot.'/vendor/autoload.php';

HarnessEnvironment::load($projectRoot);
HarnessSafetyPolicy::assertConfiguration(HarnessEnvironment::values());

$environment = HarnessEnvironment::occurrenceModeRequested()
    ? HarnessEnvironment::occurrenceProcessEnvironment()
    : HarnessEnvironment::processEnvironment();

foreach ($environment as $name => $value) {
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
