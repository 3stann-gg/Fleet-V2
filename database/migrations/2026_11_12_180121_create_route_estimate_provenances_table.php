<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        if ($connection->getDriverName() === 'mysql') {
            $version = $connection->getServerVersion();
            if (str_contains(strtolower($version), 'mariadb') || version_compare($version, '8.0.16', '<')) {
                throw new RuntimeException('Provenance requires MySQL with enforced CHECK constraints (8.0.16 or newer).');
            }
        }
        foreach ($this->statements($connection) as $statement) {
            $connection->statement($statement);
        }
    }

    /** @return list<string> */
    private function statements(Connection $connection): array
    {
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('The provenance schema supports SQLite and MySQL only.');
        }
        $blueprint = new Blueprint($connection, 'route_estimate_provenances', function (Blueprint $table) use ($driver): void {
            $table->create();
            if ($driver === 'mysql') {
                $table->engine('InnoDB');
            }
            $table->id();
            $table->foreignId('route_plan_id');
            $table->foreignId('reservation_id');
            $table->string('calculation_mode', 16);
            $table->char('calculation_uuid', 36)->nullable();
            $table->unsignedSmallInteger('proof_version')->nullable();
            $table->unsignedSmallInteger('snapshot_version')->default(1);
            $table->dateTime('departure_at_utc', 6)->nullable();
            $table->string('hospital_timezone', 64)->nullable();
            $table->char('schedule_fingerprint', 64)->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('routing_strategy', 100)->nullable();
            $table->json('routing_options')->nullable();
            $table->string('traffic_model_status', 24)->nullable();
            $table->string('verification_scope', 32)->nullable();
            $table->dateTime('calculated_at_utc', 6)->nullable();
            $table->decimal('distance_meters', 20, 6)->nullable();
            $table->decimal('duration_seconds', 20, 6)->nullable();
            $table->decimal('estimated_distance_km', 10, 2)->nullable();
            $table->unsignedInteger('estimated_duration_minutes')->nullable();
            $table->json('route_snapshot');
            $table->char('route_snapshot_hash', 64);
            $table->unsignedBigInteger('source_route_revision');
            $table->unsignedBigInteger('saved_route_revision');
            $table->string('verification_status', 16);
            $table->unsignedTinyInteger('current_marker')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable();
            $table->dateTime('accepted_at_utc', 6);
            $table->dateTime('proof_issued_at_utc', 6)->nullable();
            $table->dateTime('proof_expires_at_utc', 6)->nullable();
            $table->dateTime('ended_at_utc', 6)->nullable();
            $table->string('end_reason', 64)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique('calculation_uuid', 'rep_calculation_uq');
            $table->unique(['route_plan_id', 'current_marker'], 'rep_current_uq');
            $table->index(['route_plan_id', 'id'], 'rep_route_history_idx');
            $table->index('reservation_id', 'rep_reservation_idx');
            $table->index('accepted_by_user_id', 'rep_actor_idx');
            $table->foreign('route_plan_id', 'rep_route_fk')->references('id')->on('route_plans')->restrictOnDelete();
            $table->foreign('reservation_id', 'rep_reservation_fk')->references('id')->on('reservations')->restrictOnDelete();
            $table->foreign('accepted_by_user_id', 'rep_actor_fk')->references('id')->on('users')->nullOnDelete();
        });

        $plannedFields = [
            'calculation_uuid', 'proof_version', 'departure_at_utc', 'hospital_timezone', 'schedule_fingerprint',
            'provider', 'routing_strategy', 'routing_options', 'traffic_model_status', 'verification_scope',
            'calculated_at_utc', 'distance_meters', 'duration_seconds', 'proof_issued_at_utc', 'proof_expires_at_utc',
        ];
        $plannedRequired = implode(' AND ', array_map(fn (string $field): string => $field.' IS NOT NULL', $plannedFields));
        $manualEmpty = implode(' AND ', array_map(fn (string $field): string => $field.' IS NULL', $plannedFields));
        $checks = [
            'rep_marker_ck' => 'current_marker IS NULL OR current_marker = 1',
            'rep_mode_ck' => "calculation_mode IN ('manual', 'planned')",
            'rep_status_ck' => "verification_status IN ('current', 'superseded', 'invalidated')",
            'rep_current_state_ck' => "(verification_status = 'current' AND current_marker IS NOT NULL AND current_marker = 1 AND ended_at_utc IS NULL AND end_reason IS NULL) OR (verification_status <> 'current' AND current_marker IS NULL AND ended_at_utc IS NOT NULL AND end_reason IS NOT NULL)",
            'rep_mode_fields_ck' => "(calculation_mode = 'manual' AND {$manualEmpty}) OR (calculation_mode = 'planned' AND {$plannedRequired} AND proof_version = 2 AND hospital_timezone = 'Asia/Manila' AND provider = 'TomTom' AND verification_scope = 'requested_context_only' AND traffic_model_status IN ('unknown', 'returned', 'unavailable') AND estimated_distance_km IS NOT NULL AND estimated_duration_minutes IS NOT NULL)",
            'rep_revisions_ck' => 'source_route_revision >= 0 AND saved_route_revision > source_route_revision AND snapshot_version > 0',
            'rep_metrics_ck' => '(distance_meters IS NULL OR distance_meters >= 0) AND (duration_seconds IS NULL OR duration_seconds >= 0) AND (estimated_distance_km IS NULL OR estimated_distance_km >= 0) AND (estimated_duration_minutes IS NULL OR estimated_duration_minutes >= 0)',
            'rep_snapshot_json_ck' => 'JSON_VALID(route_snapshot)',
            'rep_options_json_ck' => 'routing_options IS NULL OR JSON_VALID(routing_options)',
        ];
        $constraints = implode(', ', array_map(
            fn (string $name, string $condition): string => "CONSTRAINT {$name} CHECK ({$condition})",
            array_keys($checks), array_values($checks),
        ));
        $statements = $blueprint->toSql();
        $create = $statements[0] ?? '';
        $closing = strrpos($create, ')');
        $suffix = $closing === false ? '' : substr($create, $closing + 1);
        $validSuffix = $driver === 'sqlite' ? $suffix === '' : preg_match("/\A(?: default character set [a-zA-Z0-9_]+)?(?: collate '[a-zA-Z0-9_]+')? engine = InnoDB\z/", $suffix) === 1;
        if ($closing === false || ! str_starts_with($create, 'create table ') || ! $validSuffix) {
            throw new RuntimeException('Unexpected schema grammar; no provenance DDL was executed.');
        }

        /* CHECK clauses belong inside CREATE so neither driver can leave an unconstrained table after a failed ALTER. */
        $statements[0] = substr($create, 0, $closing).', '.$constraints.substr($create, $closing);

        return $statements;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        if ($connection->getSchemaBuilder()->hasTable('route_estimate_provenances') && $connection->table('route_estimate_provenances')->exists()) {
            throw new RuntimeException('Rollback refused: retained route estimate provenance history exists.');
        }
        $connection->getSchemaBuilder()->dropIfExists('route_estimate_provenances');
    }
};
