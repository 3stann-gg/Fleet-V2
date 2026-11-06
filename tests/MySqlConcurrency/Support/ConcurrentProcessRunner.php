<?php

namespace Tests\MySqlConcurrency\Support;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class ConcurrentProcessRunner
{
    private Closure $processFactory;

    private Closure $treeTerminator;

    private Closure $environmentFactory;

    public function __construct(
        ?Closure $processFactory = null,
        ?Closure $treeTerminator = null,
        ?Closure $environmentFactory = null
    ) {
        $this->processFactory = $processFactory ?? fn (array $command, int $timeout): Process => $this->makeProcess($command, $timeout);
        $this->treeTerminator = $treeTerminator ?? fn (Process $process): bool => $this->terminateProcessTree($process);
        $this->environmentFactory = $environmentFactory ?? fn (): array => HarnessEnvironment::workerEnvironment();
    }

    /**
     * @param  array<string, array<int, string>>  $commands
     * @return array<string, array{exit_code: int, output: string, error_output: string}>
     */
    public function run(array $commands, int $timeoutSeconds = 15): array
    {
        if ($commands === [] || $timeoutSeconds < 1) {
            throw new RuntimeException('Concurrent commands and a positive timeout are required.');
        }

        $processes = [];
        $results = [];
        $failure = null;

        try {
            foreach ($commands as $name => $command) {
                $process = ($this->processFactory)($command, $timeoutSeconds);
                $processes[$name] = $process;
                $process->start();
            }

            foreach ($processes as $name => $process) {
                $process->wait();
                $results[$name] = $this->result($process);

                if ($results[$name]['exit_code'] !== 0) {
                    $failure = new RuntimeException("Concurrency worker {$name} exited unsuccessfully.");
                    break;
                }
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            foreach ($processes as $name => $process) {
                try {
                    if ($process->isRunning() && ! ($this->treeTerminator)($process)) {
                        $failure ??= new RuntimeException("Unable to terminate harness-owned worker {$name}.");
                    }
                } catch (Throwable $cleanupException) {
                    $failure ??= new RuntimeException("Unable to terminate harness-owned worker {$name}.", 0, $cleanupException);
                }

                $results[$name] = $this->result($process);
            }
        }

        if ($failure !== null) {
            $diagnostics = collect($results)
                ->map(fn (array $result, string $name): string => "{$name}: exit={$result['exit_code']} stderr=".$this->sanitizeDiagnostic($result['error_output']))
                ->implode('; ');
            $cause = $this->sanitizeDiagnostic($failure::class.': '.$failure->getMessage());

            throw new RuntimeException("A concurrency worker failed and all launched workers were cleaned up. Cause: {$cause}. {$diagnostics}", 0, $failure);
        }

        return $results;
    }

    private function makeProcess(array $command, int $timeoutSeconds): Process
    {
        $process = new Process($command, base_path(), ($this->environmentFactory)());
        $process->setTimeout($timeoutSeconds);

        $options = self::processOptions();

        if ($options !== []) {
            $process->setOptions($options);
        }

        return $process;
    }

    /** @return array<string, bool> */
    public static function processOptions(string $operatingSystemFamily = PHP_OS_FAMILY): array
    {
        return $operatingSystemFamily === 'Windows'
            ? ['create_process_group' => true]
            : [];
    }

    private function terminateProcessTree(Process $process): bool
    {
        if (! $process->isRunning()) {
            return true;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $pid = $process->getPid();

            if ($pid === null) {
                return false;
            }

            $terminator = new Process(['taskkill.exe', '/PID', (string) $pid, '/T', '/F']);
            $terminator->setTimeout(5);
            $terminator->run();
            $treeTerminationVerified = $terminator->getExitCode() === 0;
        } else {
            $process->stop(1, SIGKILL);
            $treeTerminationVerified = true;
        }

        if ($process->isRunning()) {
            $process->stop(1);
        }

        return $treeTerminationVerified && ! $process->isRunning();
    }

    /** @return array{exit_code: int, output: string, error_output: string} */
    private function result(Process $process): array
    {
        return [
            'exit_code' => $process->getExitCode() ?? -1,
            'output' => $process->getOutput(),
            'error_output' => $process->getErrorOutput(),
        ];
    }

    private function sanitizeDiagnostic(string $diagnostic): string
    {
        return HarnessEnvironment::sanitizeDiagnostic($diagnostic);
    }
}
