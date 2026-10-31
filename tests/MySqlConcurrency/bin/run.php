<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\MySqlConcurrency\Support\DisposableSchemaPreparer;
use Tests\MySqlConcurrency\Support\HarnessEnvironment;
use Tests\MySqlConcurrency\Support\HarnessSafetyPolicy;
use Tests\MySqlConcurrency\Support\IsolatedMySqlEnvironment;
use Tests\MySqlConcurrency\Support\NotificationOccurrenceSchemaTransition;
use Tests\MySqlConcurrency\Support\OccurrenceRunCoordinator;

$projectRoot = dirname(__DIR__, 3);

require $projectRoot.'/vendor/autoload.php';

$mode = $argv[1] ?? '';

try {
    HarnessSafetyPolicy::assertRunnerMode($mode);
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
    $occurrenceCoordinatorOwnsRelease = false;

    try {
        if ($mode === '--prepare-and-occurrence') {
            $occurrenceCoordinatorOwnsRelease = true;
            $transition = new NotificationOccurrenceSchemaTransition;

            (new OccurrenceRunCoordinator)->run(
                execute: function () use ($projectRoot, $lock, $ownerId, $transition): void {
                    $connection = DB::connection(IsolatedMySqlEnvironment::CONNECTION);

                    if (! $connection instanceof Connection) {
                        throw new RuntimeException('Unable to resolve the disposable schema connection.');
                    }

                    (new DisposableSchemaPreparer)->prepare($connection, $lock);
                    fwrite(STDOUT, "Disposable MySQL schema preparation completed under the exclusive lock.\n");
                    $transition->assertNotificationTableEmpty();
                    $transition->transitionToActiveOnly($lock);

                    $environment = array_merge(HarnessEnvironment::occurrenceProcessEnvironment(), [
                        'MYSQL_CONCURRENCY_PARENT_LOCK_NAME' => HarnessSafetyPolicy::lockName((string) config('mysql-concurrency.instance_id')),
                        'MYSQL_CONCURRENCY_PARENT_LOCK_OWNER' => (string) $ownerId,
                    ]);
                    $process = new Process(
                        HarnessEnvironment::occurrenceTestCommand($projectRoot),
                        $projectRoot,
                        $environment
                    );
                    $process->setTimeout(180);
                    $exitCode = $process->run(fn (string $type, string $output) => fwrite($type === Process::ERR ? STDERR : STDOUT, $output));
                    HarnessSafetyPolicy::assertSuccessfulOccurrenceTestExitCode($exitCode);
                    $lock->assertOwned();
                },
                cleanup: function () use ($lock, $transition): void {
                    $connection = DB::connection(IsolatedMySqlEnvironment::CONNECTION);

                    if (! $connection instanceof Connection) {
                        throw new RuntimeException('Unable to resolve the disposable schema connection for cleanup.');
                    }

                    (new DisposableSchemaPreparer)->prepare($connection, $lock);
                    $transition->assertBaseline();
                    IsolatedMySqlEnvironment::assertAllBaseTablesUseInnoDb();
                    $lock->assertOwned();
                },
                release: function () use ($lock): void {
                    $lock->release();
                }
            );

            fwrite(STDOUT, "Occurrence tests completed and the disposable schema was restored to the legacy baseline.\n");
        }

        if ($mode !== '--prepare-and-occurrence' && ! in_array($mode, ['--smoke-only', '--business-only'], true)) {
            $connection = DB::connection(IsolatedMySqlEnvironment::CONNECTION);

            if (! $connection instanceof Connection) {
                throw new RuntimeException('Unable to resolve the disposable schema connection.');
            }

            (new DisposableSchemaPreparer)->prepare($connection, $lock);
            fwrite(STDOUT, "Disposable MySQL schema preparation completed under the exclusive lock.\n");
        }

        $transition = new NotificationOccurrenceSchemaTransition;

        if ($mode !== '--prepare-and-occurrence') {
            $transition->assertBaseline();
        }

        if (! in_array($mode, ['--prepare-only', '--prepare-and-occurrence'], true)) {
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
        if (! $occurrenceCoordinatorOwnsRelease) {
            $lock->release();
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'MySQL concurrency harness refused or failed: '.HarnessEnvironment::sanitizeDiagnostic($exception->getMessage()).PHP_EOL);
    exit(1);
}
