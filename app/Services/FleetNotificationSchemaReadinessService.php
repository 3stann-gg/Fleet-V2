<?php

namespace App\Services;

use App\Models\FleetNotification;
use Illuminate\Support\Facades\Schema;
use LogicException;

class FleetNotificationSchemaReadinessService
{
    public const ACTIVE_EVENT_UNIQUE_INDEX =
        'fleet_notifications_user_active_event_unique';

    private const TABLE = 'fleet_notifications';

    /**
     * @return array{
     *     table_exists: bool,
     *     columns: array{resolved_at: bool, resolution_reason: bool, active_event_key: bool},
     *     indexes: array{active_event_unique: bool, legacy_event_unique: bool},
     *     invariants: array{unresolved_keyed_rows_have_active_keys: bool|null, unresolved_active_keys_match_event_keys: bool|null, resolved_rows_have_null_active_keys: bool|null, active_event_identities_are_unique: bool|null},
     *     violation_counts: array{unresolved_keyed_rows_missing_active_keys: int|null, unresolved_active_keys_mismatching_event_keys: int|null, resolved_rows_retaining_active_keys: int|null, duplicate_active_event_identity_groups: int|null},
     *     foundation_ready: bool,
     *     preparation_ready: bool,
     *     activation_ready: bool,
     *     activation_blockers: list<string>
     * }
     */
    public function inspect(): array
    {
        $tableExists = Schema::hasTable(self::TABLE);
        $columns = [
            'resolved_at' => $tableExists
                && Schema::hasColumn(self::TABLE, 'resolved_at'),
            'resolution_reason' => $tableExists
                && Schema::hasColumn(self::TABLE, 'resolution_reason'),
            'active_event_key' => $tableExists
                && Schema::hasColumn(self::TABLE, 'active_event_key'),
        ];
        $indexes = $tableExists
            ? Schema::getIndexes(self::TABLE)
            : [];
        $activeEventUnique = $this->hasUniqueIndex(
            $indexes,
            self::ACTIVE_EVENT_UNIQUE_INDEX,
            ['user_id', 'active_event_key']
        );
        $legacyEventUnique = $this->hasEquivalentUniqueIndex(
            $indexes,
            ['user_id', 'event_key']
        );
        $lifecycleColumnsReady = $tableExists
            && ! in_array(false, $columns, true);
        $foundationReady = $lifecycleColumnsReady && $activeEventUnique;
        $unresolvedMissingActiveKeyCount = null;
        $unresolvedMismatchedActiveKeyCount = null;
        $resolvedRetainingActiveKeyCount = null;
        $duplicateActiveIdentityGroupCount = null;

        if ($lifecycleColumnsReady) {
            $unresolvedMissingActiveKeyCount = FleetNotification::query()
                ->whereNotNull('event_key')
                ->whereNull('resolved_at')
                ->whereNull('active_event_key')
                ->count();
            $unresolvedMismatchedActiveKeyCount = FleetNotification::query()
                ->whereNull('resolved_at')
                ->whereNotNull('active_event_key')
                ->where(function ($query) {
                    $query->whereNull('event_key')
                        ->orWhereColumn('active_event_key', '!=', 'event_key');
                })
                ->count();
            $resolvedRetainingActiveKeyCount = FleetNotification::query()
                ->whereNotNull('resolved_at')
                ->whereNotNull('active_event_key')
                ->count();
            $duplicateActiveIdentityGroupCount = FleetNotification::query()
                ->select(['user_id', 'active_event_key'])
                ->whereNotNull('active_event_key')
                ->groupBy(['user_id', 'active_event_key'])
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->count();
        }

        $unresolvedRowsHaveActiveKeys = $unresolvedMissingActiveKeyCount === null
            ? null
            : $unresolvedMissingActiveKeyCount === 0;
        $unresolvedActiveKeysMatchEventKeys = $unresolvedMismatchedActiveKeyCount === null
            ? null
            : $unresolvedMismatchedActiveKeyCount === 0;
        $resolvedRowsHaveNullActiveKeys = $resolvedRetainingActiveKeyCount === null
            ? null
            : $resolvedRetainingActiveKeyCount === 0;
        $activeEventIdentitiesAreUnique = $duplicateActiveIdentityGroupCount === null
            ? null
            : $duplicateActiveIdentityGroupCount === 0;

        $activationBlockers = [];

        if (! $tableExists) {
            $activationBlockers[] = 'The fleet_notifications table is missing.';
        }

        foreach ($columns as $column => $present) {
            if (! $present) {
                $activationBlockers[] = "The {$column} column is missing.";
            }
        }

        if (! $activeEventUnique) {
            $activationBlockers[] = 'The expected unique user and active event key index is missing or invalid.';
        }

        if ($legacyEventUnique) {
            $activationBlockers[] = 'The legacy unique user and event key index is still present.';
        }

        if ($unresolvedRowsHaveActiveKeys === false) {
            $activationBlockers[] = "{$unresolvedMissingActiveKeyCount} unresolved keyed notification(s) lack an active event key.";
        }

        if ($unresolvedActiveKeysMatchEventKeys === false) {
            $activationBlockers[] = "{$unresolvedMismatchedActiveKeyCount} unresolved notification(s) have mismatched canonical and active event keys.";
        }

        if ($resolvedRowsHaveNullActiveKeys === false) {
            $activationBlockers[] = "{$resolvedRetainingActiveKeyCount} resolved notification(s) retain an active event key.";
        }

        if ($activeEventIdentitiesAreUnique === false) {
            $activationBlockers[] = "{$duplicateActiveIdentityGroupCount} duplicate active notification identity group(s) exist.";
        }

        return [
            'table_exists' => $tableExists,
            'columns' => $columns,
            'indexes' => [
                'active_event_unique' => $activeEventUnique,
                'legacy_event_unique' => $legacyEventUnique,
            ],
            'invariants' => [
                'unresolved_keyed_rows_have_active_keys' => $unresolvedRowsHaveActiveKeys,
                'unresolved_active_keys_match_event_keys' => $unresolvedActiveKeysMatchEventKeys,
                'resolved_rows_have_null_active_keys' => $resolvedRowsHaveNullActiveKeys,
                'active_event_identities_are_unique' => $activeEventIdentitiesAreUnique,
            ],
            'violation_counts' => [
                'unresolved_keyed_rows_missing_active_keys' => $unresolvedMissingActiveKeyCount,
                'unresolved_active_keys_mismatching_event_keys' => $unresolvedMismatchedActiveKeyCount,
                'resolved_rows_retaining_active_keys' => $resolvedRetainingActiveKeyCount,
                'duplicate_active_event_identity_groups' => $duplicateActiveIdentityGroupCount,
            ],
            'foundation_ready' => $foundationReady,
            'preparation_ready' => $foundationReady && $legacyEventUnique,
            'activation_ready' => $foundationReady
                && ! $legacyEventUnique
                && $unresolvedRowsHaveActiveKeys === true
                && $unresolvedActiveKeysMatchEventKeys === true
                && $resolvedRowsHaveNullActiveKeys === true
                && $activeEventIdentitiesAreUnique === true,
            'activation_blockers' => $activationBlockers,
        ];
    }

    public function assertOccurrenceActivationReady(): void
    {
        $readiness = $this->inspect();

        if ($readiness['activation_ready']) {
            return;
        }

        throw new LogicException(
            'Notification occurrence activation is not ready: '.
            implode(' ', $readiness['activation_blockers'])
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $indexes
     * @param  list<string>  $expectedColumns
     */
    private function hasUniqueIndex(
        array $indexes,
        string $expectedName,
        array $expectedColumns
    ): bool {
        foreach ($indexes as $index) {
            if (
                ($index['name'] ?? null) === $expectedName
                && ($index['unique'] ?? false) === true
                && ($index['columns'] ?? []) === $expectedColumns
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $indexes
     * @param  list<string>  $expectedColumns
     */
    private function hasEquivalentUniqueIndex(
        array $indexes,
        array $expectedColumns
    ): bool {
        foreach ($indexes as $index) {
            if (
                ($index['unique'] ?? false) === true
                && ($index['columns'] ?? []) === $expectedColumns
            ) {
                return true;
            }
        }

        return false;
    }
}
