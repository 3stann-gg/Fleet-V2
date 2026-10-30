<?php

namespace Tests\MySqlConcurrency;

use Tests\MySqlConcurrency\Support\IsolatedMySqlEnvironment;
use Tests\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->app->configurationIsCached()) {
            throw new \RuntimeException('Refusing to run MySQL concurrency tests with cached Laravel configuration.');
        }

        IsolatedMySqlEnvironment::configureIndependentConnections();
        IsolatedMySqlEnvironment::assertSafe(IsolatedMySqlEnvironment::CONTROL_CONNECTION);
        IsolatedMySqlEnvironment::assertExternalLockOwner();
        IsolatedMySqlEnvironment::assertSafe();
    }
}
