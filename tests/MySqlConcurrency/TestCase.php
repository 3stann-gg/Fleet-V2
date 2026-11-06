<?php

namespace Tests\MySqlConcurrency;

use Tests\MySqlConcurrency\Support\HarnessEnvironment;
use Tests\MySqlConcurrency\Support\HarnessSafetyPolicy;
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
        HarnessSafetyPolicy::assertLifecycleFlags([
            'occurrences' => config('fleet-notifications.occurrences.enabled') === true,
            'after_commit' => config('fleet-notifications.reconciliation.after_commit_enabled') === true,
            'scheduled_recovery' => config('fleet-notifications.reconciliation.scheduled_recovery_enabled') === true,
            'historical' => config('fleet-notifications.reconciliation.historical_enabled') === true,
        ], HarnessEnvironment::occurrenceModeRequested());
    }
}
