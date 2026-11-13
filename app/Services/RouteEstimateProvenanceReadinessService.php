<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Http\Exceptions\HttpResponseException;

final class RouteEstimateProvenanceReadinessService
{
    public function recordingEnabled(): bool
    {
        return config('services.route_estimates.provenance_recording_enabled', false) === true;
    }

    /**
     * Metadata only. No query against an absent provenance table, no migration, no cached success.
     * Installed schema is checked even with recording OFF: that switch cannot discard existing history.
     */
    public function installed(Connection $connection): bool
    {
        $schema = $connection->getSchemaBuilder();
        $table = $schema->hasTable('route_estimate_provenances');
        $revision = $schema->hasColumn('route_plans', 'estimate_revision');
        if (! $table && ! $revision && ! $this->recordingEnabled()) {
            return false;
        }
        $this->require($table && $revision);
        $driver = $connection->getDriverName();
        $this->require(in_array($driver, ['sqlite', 'mysql'], true));
        if ($driver === 'mysql') {
            $version = $connection->getServerVersion();
            $this->require(! str_contains(strtolower($version), 'mariadb') && version_compare($version, '8.0.16', '>='));
            $tables = array_map(fn (string $name): string => $connection->getTablePrefix().$name, ['route_plans', 'reservations', 'route_stops', 'audit_logs', 'route_estimate_provenances']);
            $engines = $connection->select('SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?,?,?,?,?)', [$connection->getDatabaseName(), ...$tables]);
            $this->require(count($engines) === count($tables));
            foreach ($engines as $tableEngine) {
                $this->require(strtolower($tableEngine->engine) === 'innodb');
            }
        } else {
            $this->require((int) $connection->selectOne('PRAGMA foreign_keys')->foreign_keys === 1);
        }
        $columns = collect($schema->getColumns('route_estimate_provenances'))->keyBy('name');
        $required = ['id', 'route_plan_id', 'reservation_id', 'calculation_mode', 'calculation_uuid', 'proof_version',
            'snapshot_version', 'departure_at_utc', 'hospital_timezone', 'schedule_fingerprint', 'provider',
            'routing_strategy', 'routing_options', 'traffic_model_status', 'verification_scope', 'calculated_at_utc',
            'distance_meters', 'duration_seconds', 'estimated_distance_km', 'estimated_duration_minutes',
            'route_snapshot', 'route_snapshot_hash', 'source_route_revision', 'saved_route_revision',
            'verification_status', 'current_marker', 'accepted_by_user_id', 'accepted_at_utc',
            'proof_issued_at_utc', 'proof_expires_at_utc', 'ended_at_utc', 'end_reason', 'created_at', 'updated_at'];
        $nullable = ['calculation_uuid', 'proof_version', 'departure_at_utc', 'hospital_timezone', 'schedule_fingerprint',
            'provider', 'routing_strategy', 'routing_options', 'traffic_model_status', 'verification_scope', 'calculated_at_utc',
            'distance_meters', 'duration_seconds', 'estimated_distance_km', 'estimated_duration_minutes', 'current_marker',
            'accepted_by_user_id', 'proof_issued_at_utc', 'proof_expires_at_utc', 'ended_at_utc', 'end_reason'];
        foreach ($required as $name) {
            $this->require(isset($columns[$name]) && ($name === 'id' || $columns[$name]['nullable'] === in_array($name, $nullable, true)));
        }
        foreach (['id', 'route_plan_id', 'reservation_id', 'accepted_by_user_id', 'proof_version', 'source_route_revision', 'saved_route_revision', 'snapshot_version', 'current_marker', 'estimated_duration_minutes'] as $name) {
            $this->require(str_contains(strtolower($columns[$name]['type_name']), 'int'));
            if ($driver === 'mysql') {
                $this->require(str_contains(strtolower($columns[$name]['type']), 'unsigned'));
            }
        }
        foreach (['departure_at_utc', 'calculated_at_utc', 'accepted_at_utc', 'proof_issued_at_utc', 'proof_expires_at_utc', 'ended_at_utc', 'created_at', 'updated_at'] as $name) {
            $this->require(strtolower($columns[$name]['type_name']) === 'datetime' && ($driver === 'sqlite' || strtolower($columns[$name]['type']) === 'datetime(6)'));
        }
        foreach (['route_snapshot', 'routing_options'] as $name) {
            $this->require($driver === 'sqlite' ? in_array($columns[$name]['type_name'], ['text', 'json'], true) : $columns[$name]['type_name'] === 'json');
        }
        foreach (['calculation_mode' => 'varchar(16)', 'calculation_uuid' => 'char(36)', 'hospital_timezone' => 'varchar(64)',
            'schedule_fingerprint' => 'char(64)', 'provider' => 'varchar(32)', 'routing_strategy' => 'varchar(100)',
            'traffic_model_status' => 'varchar(24)', 'verification_scope' => 'varchar(32)', 'route_snapshot_hash' => 'char(64)',
            'verification_status' => 'varchar(16)', 'end_reason' => 'varchar(64)'] as $name => $type) {
            $this->require($driver === 'sqlite' ? $columns[$name]['type_name'] === 'varchar' : strtolower($columns[$name]['type']) === $type);
        }
        foreach (['distance_meters' => 'decimal(20,6)', 'duration_seconds' => 'decimal(20,6)', 'estimated_distance_km' => 'decimal(10,2)'] as $name => $type) {
            $this->require($driver === 'sqlite' ? in_array($columns[$name]['type_name'], ['numeric', 'decimal'], true) : strtolower(str_replace(' ', '', $columns[$name]['type'])) === $type);
        }
        $routeRevision = collect($schema->getColumns('route_plans'))->firstWhere('name', 'estimate_revision');
        $this->require($routeRevision !== null && ! $routeRevision['nullable'] && str_contains($routeRevision['type_name'], 'int') && trim((string) $routeRevision['default'], "'\"") === '0');
        $this->require(trim((string) $columns['snapshot_version']['default'], "'\"") === '1');
        $indexes = collect($schema->getIndexes('route_estimate_provenances'))->keyBy('name');
        $this->require($indexes->contains(fn (array $index): bool => $index['primary'] && $index['columns'] === ['id']));
        foreach (['rep_current_uq' => [['route_plan_id', 'current_marker'], true], 'rep_calculation_uq' => [['calculation_uuid'], true],
            'rep_route_history_idx' => [['route_plan_id', 'id'], false], 'rep_reservation_idx' => [['reservation_id'], false],
            'rep_actor_idx' => [['accepted_by_user_id'], false]] as $name => [$fields, $unique]) {
            $this->require(isset($indexes[$name]) && $indexes[$name]['columns'] === $fields && $indexes[$name]['unique'] === $unique);
        }
        $foreign = collect($schema->getForeignKeys('route_estimate_provenances'))->keyBy(fn (array $key): string => implode(',', $key['columns']));
        foreach (['route_plan_id' => ['route_plans', 'restrict'], 'reservation_id' => ['reservations', 'restrict'], 'accepted_by_user_id' => ['users', 'set null']] as $name => [$target, $deletion]) {
            $this->require(isset($foreign[$name]) && $foreign[$name]['foreign_table'] === $target && $foreign[$name]['foreign_columns'] === ['id'] && strtolower($foreign[$name]['on_delete']) === $deletion);
        }
        $checks = $this->checks($connection);
        foreach ($this->expectedChecks() as $name => $condition) {
            $this->require(isset($checks[$name]) && $this->condition($checks[$name], $driver === 'mysql') === $this->condition($condition));
        }

        return true;
    }

    /** @return array<string, string> */
    private function checks(Connection $connection): array
    {
        $table = $connection->getTablePrefix().'route_estimate_provenances';
        if ($connection->getDriverName() === 'sqlite') {
            $sql = $connection->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])?->sql;
            $this->require(is_string($sql));
            preg_match_all('/CONSTRAINT\s+(rep_[a-z_]+)\s+CHECK\s*\((.*?)(?:\)(?=,\s*CONSTRAINT)|\)\s*\)\s*$)/si', $sql, $matches, PREG_SET_ORDER);

            return array_column($matches, 2, 1);
        }
        $rows = $connection->select('SELECT tc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS clause, tc.ENFORCED AS enforced FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA = ? AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = ?', [$connection->getDatabaseName(), $table, 'CHECK']);
        $checks = [];
        foreach ($rows as $row) {
            $this->require($row->enforced === 'YES');
            $checks[$row->name] = $row->clause;
        }

        return $checks;
    }

    /** @return array<string, string> */
    private function expectedChecks(): array
    {
        $fields = ['calculation_uuid', 'proof_version', 'departure_at_utc', 'hospital_timezone', 'schedule_fingerprint', 'provider', 'routing_strategy', 'routing_options', 'traffic_model_status', 'verification_scope', 'calculated_at_utc', 'distance_meters', 'duration_seconds', 'proof_issued_at_utc', 'proof_expires_at_utc'];
        $required = implode(' AND ', array_map(fn (string $field): string => $field.' IS NOT NULL', $fields));
        $empty = implode(' AND ', array_map(fn (string $field): string => $field.' IS NULL', $fields));

        return [
            'rep_marker_ck' => 'current_marker IS NULL OR current_marker = 1',
            'rep_mode_ck' => "calculation_mode IN ('manual', 'planned')",
            'rep_status_ck' => "verification_status IN ('current', 'superseded', 'invalidated')",
            'rep_current_state_ck' => "(verification_status = 'current' AND current_marker IS NOT NULL AND current_marker = 1 AND ended_at_utc IS NULL AND end_reason IS NULL) OR (verification_status <> 'current' AND current_marker IS NULL AND ended_at_utc IS NOT NULL AND end_reason IS NOT NULL)",
            'rep_mode_fields_ck' => "(calculation_mode = 'manual' AND {$empty}) OR (calculation_mode = 'planned' AND {$required} AND proof_version = 2 AND hospital_timezone = 'Asia/Manila' AND provider = 'TomTom' AND verification_scope = 'requested_context_only' AND traffic_model_status IN ('unknown', 'returned', 'unavailable') AND estimated_distance_km IS NOT NULL AND estimated_duration_minutes IS NOT NULL)",
            'rep_revisions_ck' => 'source_route_revision >= 0 AND saved_route_revision > source_route_revision AND snapshot_version > 0',
            'rep_metrics_ck' => '(distance_meters IS NULL OR distance_meters >= 0) AND (duration_seconds IS NULL OR duration_seconds >= 0) AND (estimated_distance_km IS NULL OR estimated_distance_km >= 0) AND (estimated_duration_minutes IS NULL OR estimated_duration_minutes >= 0)',
            'rep_snapshot_json_ck' => 'JSON_VALID(route_snapshot)',
            'rep_options_json_ck' => 'routing_options IS NULL OR JSON_VALID(routing_options)',
        ];
    }

    /**
     * MySQL metadata may escape literal delimiters themselves. Recognize only balanced, charset-prefixed
     * metadata literals; decode their boundaries and SQL doubled apostrophes, never arbitrary backslashes.
     * Preserve literal case and the existing boolean tree; unsupported or ambiguous forms fail closed.
     */
    private function condition(string $condition, bool $mysqlMetadata = false): array
    {
        $pattern = <<<'REGEX'
        ~\G(?:
            \s+
            |`[^`]+`
            |"[^"]+"
            |(?<metadata>_(?i:utf8mb4|utf8mb3|utf8|ascii|latin1|binary)\s*\\'(?:''|[^'\\\x00-\x1f\x7f])*\\')
            |(?:_[a-zA-Z0-9]+\s*)?'(?:''|[^'])*'
            |[a-zA-Z_][a-zA-Z0-9_]*
            |[0-9]+|>=|<=|<>|!=|[=><(),]
        )~x
        REGEX;
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($condition)) {
            $this->require(preg_match($pattern, $condition, $match, 0, $offset) === 1);
            $token = $match[0];
            $offset += strlen($token);
            if (trim($token) === '') {
                continue;
            }
            if (str_contains($token, "'")) {
                $metadataLiteral = ($match['metadata'] ?? '') !== '';
                $this->require(! $metadataLiteral || $mysqlMetadata);
                $quote = strpos($token, "'");
                $literal = substr($token, $quote + 1, $metadataLiteral ? -2 : -1);
                if ($mysqlMetadata) {
                    $this->require(! str_contains($literal, '\\') && preg_match('/[\x00-\x1f\x7f]/', $literal) === 0);
                }
                $tokens[] = 'literal:'.str_replace("''", "'", $literal);
            } else {
                $tokens[] = strtolower(trim($token, '`"'));
            }
        }
        $position = 0;
        $tree = $this->booleanExpression($tokens, $position, 'or');
        $this->require($position === count($tokens));

        return $tree;
    }

    /** @param list<string> $tokens */
    private function booleanExpression(array $tokens, int &$position, string $operator): array
    {
        $read = function () use ($tokens, &$position, $operator): array {
            return $operator === 'or' ? $this->booleanExpression($tokens, $position, 'and') : $this->predicate($tokens, $position);
        };
        $parts = [$read()];
        while (($tokens[$position] ?? null) === $operator) {
            $position++;
            $parts[] = $read();
        }

        $flattened = [];
        foreach ($parts as $part) {
            array_push($flattened, ...($part[0] === $operator ? $part[1] : [$part]));
        }

        return count($flattened) === 1 ? $flattened[0] : [$operator, $flattened];
    }

    /** @param list<string> $tokens */
    private function predicate(array $tokens, int &$position): array
    {
        if (($tokens[$position] ?? null) === '(') {
            $position++;
            $result = $this->booleanExpression($tokens, $position, 'or');
            $this->require(($tokens[$position] ?? null) === ')');
            $position++;

            return $result;
        }
        $atom = [];
        $depth = 0;
        while (isset($tokens[$position])) {
            $token = $tokens[$position];
            if ($depth === 0 && in_array($token, ['and', 'or', ')'], true)) {
                break;
            }
            $depth += $token === '(' ? 1 : ($token === ')' ? -1 : 0);
            $this->require($depth >= 0);
            $atom[] = $token;
            $position++;
        }
        $this->require($atom !== [] && $depth === 0);

        return ['predicate', $atom];
    }

    private function require(bool $ready): void
    {
        if (! $ready) {
            throw new HttpResponseException(response()->json(['success' => false, 'code' => 'ROUTE_PROVENANCE_SCHEMA_NOT_READY', 'message' => 'Route estimate provenance storage is not ready. No route changes were saved.'], 503));
        }
    }
}
