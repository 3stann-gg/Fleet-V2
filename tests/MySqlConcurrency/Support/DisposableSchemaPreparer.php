<?php

namespace Tests\MySqlConcurrency\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

final class DisposableSchemaPreparer
{
    public function prepare(Connection $connection, MySqlAdvisoryLock $lock): void
    {
        $lock->assertOwned();
        IsolatedMySqlEnvironment::assertSafe();
        $objects = $connection->table('information_schema.tables')
            ->where('TABLE_SCHEMA', HarnessSafetyPolicy::DATABASE)
            ->orderBy('TABLE_NAME')
            ->selectRaw('TABLE_SCHEMA AS schema_name, TABLE_NAME AS object_name, TABLE_TYPE AS object_type')
            ->get();

        foreach ($objects as $object) {
            if (! property_exists($object, 'schema_name') || $object->schema_name !== HarnessSafetyPolicy::DATABASE) {
                throw new RuntimeException('Schema preparation received an unexpected information_schema identity.');
            }

            if (! property_exists($object, 'object_name') || ! is_string($object->object_name) || $object->object_name === '') {
                throw new RuntimeException('Schema preparation received an invalid object name.');
            }

            if (! property_exists($object, 'object_type') || ! in_array($object->object_type, ['BASE TABLE', 'VIEW'], true)) {
                throw new RuntimeException('Schema preparation received an invalid object type.');
            }
        }

        $objects = $objects->sortBy(fn (object $object): int => $object->object_type === 'VIEW' ? 0 : 1);

        $connection->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($objects as $object) {
                $lock->assertOwned();
                IsolatedMySqlEnvironment::assertSafe();
                $name = str_replace('`', '``', $object->object_name);
                $type = $object->object_type === 'VIEW' ? 'VIEW' : 'TABLE';
                $connection->statement("DROP {$type} IF EXISTS `{$name}`");
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $lock->assertOwned();
        $exitCode = Artisan::call('migrate', [
            '--database' => IsolatedMySqlEnvironment::CONNECTION,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException('The guarded migration run failed.');
        }

        $lock->assertOwned();
        IsolatedMySqlEnvironment::assertSafe();
        IsolatedMySqlEnvironment::assertAllBaseTablesUseInnoDb();
    }
}
