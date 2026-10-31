<?php

namespace Tests\MySqlConcurrency\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NotificationOccurrenceSchemaTransition
{
    public const TEST_TITLE_UNIQUE_INDEX = 'cc_fleet_notifications_title_unique';

    public function assertBaseline(): void
    {
        IsolatedMySqlEnvironment::assertSafe();
        HarnessSafetyPolicy::assertNotificationIndexes(
            $this->indexRows(),
            legacyExpected: true
        );
    }

    public function assertActiveOnly(): void
    {
        IsolatedMySqlEnvironment::assertSafe();
        HarnessSafetyPolicy::assertNotificationIndexes(
            $this->indexRows(),
            legacyExpected: false
        );
    }

    public function assertNotificationTableEmpty(): void
    {
        $count = $this->connection()
            ->table(HarnessSafetyPolicy::NOTIFICATION_TABLE)
            ->count();

        if ($count !== 0) {
            throw new RuntimeException('Occurrence transition requires an empty disposable notification table.');
        }
    }

    public function transitionToActiveOnly(MySqlAdvisoryLock $lock): void
    {
        $lock->assertOwned();
        $this->assertBaseline();
        $this->assertNotificationTableEmpty();
        $this->connection()->statement(
            'ALTER TABLE `fleet_notifications` DROP INDEX `'.HarnessSafetyPolicy::LEGACY_EVENT_UNIQUE_INDEX.'`'
        );
        $lock->assertOwned();
        $this->assertActiveOnly();
    }

    public function installTestTitleUniqueIndex(): void
    {
        $this->assertChildMayMutateDisposableSchema();
        $this->connection()->statement(
            'ALTER TABLE `fleet_notifications` ADD UNIQUE INDEX `'.self::TEST_TITLE_UNIQUE_INDEX.'` (`title`)'
        );
    }

    public function removeTestTitleUniqueIndex(): void
    {
        $this->assertChildMayMutateDisposableSchema();

        if (! $this->hasIndex(self::TEST_TITLE_UNIQUE_INDEX)) {
            return;
        }

        $this->connection()->statement(
            'ALTER TABLE `fleet_notifications` DROP INDEX `'.self::TEST_TITLE_UNIQUE_INDEX.'`'
        );
    }

    public function installLegacyIndexForReadinessTest(): void
    {
        $this->assertChildMayMutateDisposableSchema();

        if ($this->hasIndex(HarnessSafetyPolicy::LEGACY_EVENT_UNIQUE_INDEX)) {
            throw new RuntimeException('The legacy notification index already exists in occurrence mode.');
        }

        $this->connection()->statement(
            'ALTER TABLE `fleet_notifications` ADD UNIQUE INDEX `'.HarnessSafetyPolicy::LEGACY_EVENT_UNIQUE_INDEX.'` (`user_id`, `event_key`)'
        );
    }

    public function removeLegacyIndexAfterReadinessTest(): void
    {
        $this->assertChildMayMutateDisposableSchema();

        if (! $this->hasIndex(HarnessSafetyPolicy::LEGACY_EVENT_UNIQUE_INDEX)) {
            return;
        }

        $this->connection()->statement(
            'ALTER TABLE `fleet_notifications` DROP INDEX `'.HarnessSafetyPolicy::LEGACY_EVENT_UNIQUE_INDEX.'`'
        );
        $this->assertActiveOnly();
    }

    public function replaceActiveIndexForMetadataTest(string $replacementName): void
    {
        $this->assertChildMayMutateDisposableSchema();

        if (preg_match('/\Acc_[a-z0-9_]{1,40}\z/', $replacementName) !== 1) {
            throw new RuntimeException('The test replacement index name is not allowlisted.');
        }

        $this->connection()->statement(
            'ALTER TABLE `fleet_notifications` DROP INDEX `'.HarnessSafetyPolicy::ACTIVE_EVENT_UNIQUE_INDEX.'`, '.
            "ADD UNIQUE INDEX `{$replacementName}` (`active_event_key`, `user_id`)"
        );
    }

    public function restoreActiveIndexAfterMetadataTest(string $replacementName): void
    {
        $this->assertChildMayMutateDisposableSchema();

        if (preg_match('/\Acc_[a-z0-9_]{1,40}\z/', $replacementName) !== 1) {
            throw new RuntimeException('The test replacement index name is not allowlisted.');
        }

        $this->connection()->statement(
            "ALTER TABLE `fleet_notifications` DROP INDEX `{$replacementName}`, ".
            'ADD UNIQUE INDEX `'.HarnessSafetyPolicy::ACTIVE_EVENT_UNIQUE_INDEX.'` (`user_id`, `active_event_key`)'
        );
        $this->assertActiveOnly();
    }

    public function dropResolutionReasonForReadinessTest(): void
    {
        $this->assertChildMayMutateDisposableSchema();
        $this->connection()->statement(
            'ALTER TABLE `fleet_notifications` DROP COLUMN `resolution_reason`'
        );
    }

    public function restoreResolutionReasonAfterReadinessTest(): void
    {
        $this->assertChildMayMutateDisposableSchema();

        if ($this->hasColumn('resolution_reason')) {
            return;
        }

        $this->connection()->statement(
            'ALTER TABLE `fleet_notifications` ADD COLUMN `resolution_reason` VARCHAR(100) NULL AFTER `resolved_at`'
        );
    }

    /** @return array<int, object> */
    private function indexRows(): array
    {
        return $this->connection()
            ->table('information_schema.statistics')
            ->where('TABLE_SCHEMA', HarnessSafetyPolicy::DATABASE)
            ->where('TABLE_NAME', HarnessSafetyPolicy::NOTIFICATION_TABLE)
            ->orderBy('INDEX_NAME')
            ->orderBy('SEQ_IN_INDEX')
            ->selectRaw(
                'TABLE_SCHEMA AS schema_name, TABLE_NAME AS table_name, INDEX_NAME AS index_name, '.
                'NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS sequence_number, COLUMN_NAME AS column_name'
            )
            ->get()
            ->all();
    }

    private function hasIndex(string $name): bool
    {
        return $this->connection()
            ->table('information_schema.statistics')
            ->where('TABLE_SCHEMA', HarnessSafetyPolicy::DATABASE)
            ->where('TABLE_NAME', HarnessSafetyPolicy::NOTIFICATION_TABLE)
            ->where('INDEX_NAME', $name)
            ->exists();
    }

    private function hasColumn(string $name): bool
    {
        return $this->connection()
            ->table('information_schema.columns')
            ->where('TABLE_SCHEMA', HarnessSafetyPolicy::DATABASE)
            ->where('TABLE_NAME', HarnessSafetyPolicy::NOTIFICATION_TABLE)
            ->where('COLUMN_NAME', $name)
            ->exists();
    }

    private function assertChildMayMutateDisposableSchema(): void
    {
        if (! HarnessEnvironment::occurrenceModeRequested()) {
            throw new RuntimeException('Test-only occurrence DDL requires the dedicated occurrence mode.');
        }

        IsolatedMySqlEnvironment::assertSafe();
        IsolatedMySqlEnvironment::assertExternalLockOwner();
    }

    private function connection(): Connection
    {
        $connection = DB::connection(IsolatedMySqlEnvironment::CONNECTION);

        if (! $connection instanceof Connection) {
            throw new RuntimeException('The disposable occurrence connection is unavailable.');
        }

        return $connection;
    }
}
