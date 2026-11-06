<?php

namespace Tests\MySqlConcurrency\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AuditFailureConstraint
{
    public const PREFIX = 'cc_fail_notification_audit_';

    public function install(int $notificationId): string
    {
        $this->removeInterruptedConstraints();
        $constraintName = self::PREFIX.bin2hex(random_bytes(6));
        $this->connection()->statement(self::addStatement($constraintName, $notificationId));

        return $constraintName;
    }

    public function remove(string $constraintName): void
    {
        self::assertConstraintName($constraintName);

        if (! $this->existingConstraintNames()->containsStrict($constraintName)) {
            return;
        }

        $this->connection()->statement(self::dropStatement($constraintName));
    }

    public function removeInterruptedConstraints(): void
    {
        foreach ($this->existingConstraintNames() as $constraintName) {
            if (! str_starts_with($constraintName, self::PREFIX)) {
                continue;
            }

            self::assertConstraintName($constraintName);
            $this->connection()->statement(self::dropStatement($constraintName));
        }
    }

    public static function addStatement(string $constraintName, int $notificationId): string
    {
        self::assertConstraintName($constraintName);

        if ($notificationId < 1) {
            throw new InvalidArgumentException('A positive notification ID is required for the audit failure constraint.');
        }

        return "ALTER TABLE `audit_logs` ADD CONSTRAINT `{$constraintName}` CHECK (NOT (`action` = 'Notification Resolved' AND `auditable_id` = {$notificationId}))";
    }

    public static function dropStatement(string $constraintName): string
    {
        self::assertConstraintName($constraintName);

        return "ALTER TABLE `audit_logs` DROP CHECK `{$constraintName}`";
    }

    private function existingConstraintNames(): Collection
    {
        return $this->connection()
            ->table('information_schema.table_constraints')
            ->where('CONSTRAINT_SCHEMA', HarnessSafetyPolicy::DATABASE)
            ->where('TABLE_NAME', 'audit_logs')
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->selectRaw('CONSTRAINT_NAME AS constraint_name')
            ->get()
            ->map(function (object $constraint): string {
                if (! property_exists($constraint, 'constraint_name') || ! is_string($constraint->constraint_name)) {
                    throw new InvalidArgumentException('The audit constraint query returned an invalid identifier.');
                }

                return $constraint->constraint_name;
            });
    }

    private function connection(): Connection
    {
        IsolatedMySqlEnvironment::assertSafe();

        return DB::connection(IsolatedMySqlEnvironment::CONNECTION);
    }

    private static function assertConstraintName(string $constraintName): void
    {
        if (! preg_match('/\A'.self::PREFIX.'[a-f0-9]{12}\z/', $constraintName)) {
            throw new InvalidArgumentException('The audit failure constraint name is not harness-owned.');
        }
    }
}
