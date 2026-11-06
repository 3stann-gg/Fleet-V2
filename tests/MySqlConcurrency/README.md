# Disposable MySQL concurrency suite

This suite is separate from the normal SQLite configuration. It must never use the application's normal `.env`, MySQL credentials, or database.

## One-time local environment file

Copy `.env.mysql-concurrency.example` to `.env.mysql-concurrency`. The target file is ignored by Git. Generate a new UUIDv4 every time the disposable container is rebuilt and generate independent random application and root passwords. Never reuse application, development, or production credentials.

```powershell
Copy-Item .env.mysql-concurrency.example .env.mysql-concurrency
[guid]::NewGuid().ToString()
[Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
```

Place the generated values in `MYSQL_CONCURRENCY_INSTANCE_ID`, `MYSQL_CONCURRENCY_PASSWORD`, and `MYSQL_CONCURRENCY_ROOT_PASSWORD`. Do not commit the resulting file.

## Safety model

The harness fails closed unless all of these conditions are verified:

- `APP_ENV` is exactly `testing` and the explicit opt-in is `1`.
- The connection is exactly `127.0.0.1:33087`, database `fleet_concurrency_test`, and account `fleet_concurrency@%`.
- `DB_URL` is empty and the dedicated connection has no URL fallback.
- MySQL reports version 8, InnoDB, the expected server UUID, and an independent connection ID.
- The read-only sentinel lives in the separate `fleet_concurrency_guard_test` schema and contains the externally generated instance UUID and current MySQL server UUID. The test account receives only `SELECT` on that sentinel table.
- No active, mandatory, automatically activated, global, cross-schema, administrative, proxy, or grant-option privileges exist.
- Every migrated base table uses InnoDB.
- One MySQL advisory lock covers preparation and smoke-test execution. Direct dedicated-suite execution is refused.

The Compose service binds only to localhost and stores MySQL data in container `tmpfs`. Rebuilding the container requires a new instance UUID because the previous sentinel identifies a different disposable instance.

## Guarded setup and smoke test

After Docker Desktop is running:

```powershell
docker compose --env-file .env.mysql-concurrency -f docker-compose.mysql-concurrency.yml config
docker compose --env-file .env.mysql-concurrency -f docker-compose.mysql-concurrency.yml up -d --wait
php tests/MySqlConcurrency/bin/run.php --prepare-and-smoke
```

The launcher holds the instance-bound advisory lock from before schema preparation until the four smoke tests finish. Preparation checks lock ownership before every destructive statement. The dedicated PHPUnit configuration cannot be invoked directly because its bootstrap requires proof that the launcher still owns that lock.

Preparation without smoke tests remains explicitly guarded:

```powershell
php tests/MySqlConcurrency/bin/prepare.php --rebuild-disposable-test-schema
```

Stop and destroy the disposable service when finished:

```powershell
docker compose --env-file .env.mysql-concurrency -f docker-compose.mysql-concurrency.yml down --volumes --remove-orphans
```

Generate a new instance UUID before the next container initialization.

## Worker cleanup and current scope

The process runner tracks only workers it starts. On Windows it creates a new process group and uses `taskkill /T /F` for a timed-out worker PID, then verifies the direct process stopped. On other platforms workers must remain single-level because descendant cleanup is not guaranteed without a platform-specific process supervisor.

`MySqlEnvironmentTest.php` contains the live environment smoke tests. `MaintenanceNotificationConcurrencyTest.php` contains the ten baseline business scenarios. Run either suite only through its explicit guarded mode.

## Future occurrence index-transition tests

The occurrence suite models the future schema in which
`UNIQUE(user_id, event_key)` has been removed while
`UNIQUE(user_id, active_event_key)` remains authoritative. It is intentionally
separate from smoke and baseline business tests:

```powershell
php tests/MySqlConcurrency/bin/run.php --prepare-and-occurrence
```

This is a destructive command for the disposable `fleet_concurrency_test`
schema only. The guarded runner acquires the instance-specific advisory lock,
rebuilds the schema, verifies the exact baseline index contract and an empty
notification table, drops only the exact legacy index, and verifies the exact
active-only contract before starting the occurrence child process. The child
enables only `FLEET_NOTIFICATION_OCCURRENCES_ENABLED`; reconciliation, scheduled
recovery, and historical reconciliation remain disabled.

MySQL index DDL commits implicitly, so the runner does not claim transaction
rollback protection. Its `finally`-style coordinator always performs a full
guarded disposable-schema rebuild and verifies that the legacy baseline index
contract has been restored. A cleanup failure is reported alongside any test
failure and must be treated as a hard failure. If the process or machine is
interrupted before cleanup finishes, do not use `--smoke-only` or
`--business-only`; those modes reject an active-only schema. Recover with the
guarded baseline preparation command after confirming the endpoint, database,
sentinel, account, privileges, and advisory-lock safeguards:

```powershell
php tests/MySqlConcurrency/bin/run.php --prepare-only
```

The occurrence scenarios persist and inspect real notification history in the
disposable schema. Scenario 9 previously exposed a production source-authority
coordination defect. After the maintenance occurrence authority guard was
implemented, the guarded MySQL 8.4 test verified reconciliation completing
before stale creation, rejection as `source_rejected` / `schedule_changed`, and
zero unresolved obsolete notifications. Additional overlapping Maintenance
concurrency scenarios remain pending and must not be treated as verified by
this result. A nonzero suite result remains evidence of a production locking or
design gap, not permission to weaken or skip an assertion.
