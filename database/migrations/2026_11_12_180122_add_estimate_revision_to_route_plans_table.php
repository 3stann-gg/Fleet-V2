<?php

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
        if (! in_array($connection->getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('The estimate revision schema supports SQLite and MySQL only.');
        }
        $blueprint = new Blueprint($connection, 'route_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('estimate_revision')->default(0);
        });
        foreach ($blueprint->toSql() as $statement) {
            if ($connection->getDriverName() === 'sqlite') {
                $statement .= ' CHECK (estimate_revision >= 0)';
            }
            $connection->statement($statement);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        $schema = $connection->getSchemaBuilder();
        if ($schema->hasTable('route_estimate_provenances') && $connection->table('route_estimate_provenances')->exists()) {
            throw new RuntimeException('Rollback refused: retained provenance history requires estimate revisions.');
        }
        if ($connection->table('route_plans')->where('estimate_revision', '<>', 0)->exists()) {
            throw new RuntimeException('Rollback refused: route estimate revisions have advanced.');
        }
        $schema->table('route_plans', function (Blueprint $table): void {
            $table->dropColumn('estimate_revision');
        });
    }
};
