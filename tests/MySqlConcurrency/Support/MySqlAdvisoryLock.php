<?php

namespace Tests\MySqlConcurrency\Support;

use Closure;
use RuntimeException;

final class MySqlAdvisoryLock
{
    private bool $acquired = false;

    /** @param Closure(string, array<int, mixed>): mixed $scalarQuery */
    public function __construct(
        private readonly string $name,
        private readonly int $timeoutSeconds,
        private readonly Closure $scalarQuery
    ) {
        if ($timeoutSeconds < 0 || strlen($name) > 64) {
            throw new RuntimeException('Invalid MySQL advisory lock configuration.');
        }
    }

    public function acquire(): int
    {
        $result = ($this->scalarQuery)('SELECT GET_LOCK(?, ?)', [$this->name, $this->timeoutSeconds]);

        if ((int) $result !== 1) {
            throw new RuntimeException('Unable to acquire the exclusive MySQL concurrency harness lock.');
        }

        $this->acquired = true;

        return $this->assertOwned();
    }

    public function assertOwned(): int
    {
        if (! $this->acquired) {
            throw new RuntimeException('The MySQL concurrency harness lock was not acquired.');
        }

        $connectionId = (int) ($this->scalarQuery)('SELECT CONNECTION_ID()', []);
        $ownerId = (int) ($this->scalarQuery)('SELECT IS_USED_LOCK(?)', [$this->name]);

        if ($connectionId < 1 || $ownerId !== $connectionId) {
            throw new RuntimeException('MySQL concurrency harness lock ownership was lost or cannot be proven.');
        }

        return $connectionId;
    }

    public function release(): void
    {
        if (! $this->acquired) {
            return;
        }

        $released = ($this->scalarQuery)('SELECT RELEASE_LOCK(?)', [$this->name]);
        $this->acquired = false;

        if ((int) $released !== 1) {
            throw new RuntimeException('MySQL concurrency harness lock release could not be verified.');
        }
    }
}
