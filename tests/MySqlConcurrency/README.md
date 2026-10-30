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

`MySqlEnvironmentTest.php` contains the live environment smoke tests. The ten tests in `MaintenanceNotificationConcurrencyTest.php` remain explicit pending specifications; they are not evidence that business concurrency behavior works.
