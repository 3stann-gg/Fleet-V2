<?php

namespace Tests\MySqlConcurrency\Support;

use Closure;
use RuntimeException;
use Throwable;

final class OccurrenceRunCoordinator
{
    /**
     * @param  Closure(): void  $execute
     * @param  Closure(): void  $cleanup
     * @param  Closure(): void  $release
     */
    public function run(Closure $execute, Closure $cleanup, Closure $release): void
    {
        $executionFailure = null;
        $cleanupFailure = null;
        $releaseFailure = null;

        try {
            $execute();
        } catch (Throwable $exception) {
            $executionFailure = $exception;
        }

        try {
            $cleanup();
        } catch (Throwable $exception) {
            $cleanupFailure = $exception;
        }

        try {
            $release();
        } catch (Throwable $exception) {
            $releaseFailure = $exception;
        }

        if ($executionFailure === null && $cleanupFailure === null && $releaseFailure === null) {
            return;
        }

        $parts = [];

        if ($executionFailure !== null) {
            $parts[] = 'Occurrence execution failed: '.HarnessEnvironment::sanitizeDiagnostic(
                $executionFailure::class.': '.$executionFailure->getMessage()
            );
        }

        if ($cleanupFailure !== null) {
            $parts[] = 'Disposable schema cleanup failed: '.HarnessEnvironment::sanitizeDiagnostic(
                $cleanupFailure::class.': '.$cleanupFailure->getMessage()
            );
        }

        if ($releaseFailure !== null) {
            $parts[] = 'Advisory lock release failed: '.HarnessEnvironment::sanitizeDiagnostic(
                $releaseFailure::class.': '.$releaseFailure->getMessage()
            );
        }

        throw new RuntimeException(
            implode(' ', $parts),
            0,
            $executionFailure ?? $cleanupFailure ?? $releaseFailure
        );
    }
}
