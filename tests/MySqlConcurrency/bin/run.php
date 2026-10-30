<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\MySqlConcurrency\Support\DisposableSchemaPreparer;
use Tests\MySqlConcurrency\Support\HarnessEnvironment;
use Tests\MySqlConcurrency\Support\HarnessSafetyPolicy;
use Tests\MySqlConcurrency\Support\IsolatedMySqlEnvironment;

$projectRoot = dirname(__DIR__, 3);

require $projectRoot.'/vendor/autoload.php';

$mode = $argv[1] ?? '';

if (! in_array($mode, ['--prepare-only', '--smoke-only', '--business-only', '--prepare-and-smoke'], true)) {
    fwrite(STDERR, "Refusing to run. Choose --prepare-only, --smoke-only, --business-only, or --prepare-and-smoke explicitly.\n");
    exit(1);
}

try {
    HarnessEnvironment::load($projectRoot);
    HarnessSafetyPolicy::assertConfiguration(HarnessEnvironment::values());

    foreach (HarnessEnvironment::processEnvironment() as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    $app = require $projectRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if ($app->configurationIsCached()) {
        throw new RuntimeException('Refusing to run with cached Laravel configuration.');
    }

    IsolatedMySqlEnvironment::configureIndependentConnections();
    IsolatedMySqlEnvironment::assertSafe();
    IsolatedMySqlEnvironment::assertSafe(IsolatedMySqlEnvironment::CONTROL_CONNECTION);
    $lock = IsolatedMySqlEnvironment::advisoryLock();
    $ownerId = $lock->acquire();

    try {
        if (! in_array($mode, ['--smoke-only', '--business-only'], true)) {
            $connection = DB::connection(IsolatedMySqlEnvironment::CONNECTION);

            if (! $connection instanceof Connection) {
                throw new RuntimeException('Unable to resolve the disposable schema connection.');
            }

            (new DisposableSchemaPreparer)->prepare($connection, $lock);
            fwrite(STDOUT, "Disposable MySQL schema preparation completed under the exclusive lock.\n");
        }

        if ($mode !== '--prepare-only') {
            $lock->assertOwned();
            $environment = array_merge(HarnessEnvironment::processEnvironment(), [
                'MYSQL_CONCURRENCY_PARENT_LOCK_NAME' => HarnessSafetyPolicy::lockName((string) config('mysql-concurrency.instance_id')),
                'MYSQL_CONCURRENCY_PARENT_LOCK_OWNER' => (string) $ownerId,
            ]);
            $command = $mode === '--business-only'
                ? HarnessEnvironment::businessTestCommand($projectRoot)
                : HarnessEnvironment::smokeTestCommand($projectRoot);
            $process = new Process($command, $projectRoot, $environment);
            $process->setTimeout(120);
            $exitCode = $process->run(fn (string $type, string $output) => fwrite($type === Process::ERR ? STDERR : STDOUT, $output));

            if ($mode === '--business-only') {
                HarnessSafetyPolicy::assertSuccessfulBusinessTestExitCode($exitCode);
            } else {
                HarnessSafetyPolicy::assertSuccessfulSmokeTestExitCode($exitCode);
            }

            $lock->assertOwned();
        }
    } finally {
        $lock->release();
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "MySQL concurrency harness refused or failed: {$exception->getMessage()}\n");
    exit(1);
}
