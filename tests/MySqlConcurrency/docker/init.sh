#!/bin/bash
set -Eeuo pipefail

if [[ ! "${MYSQL_CONCURRENCY_INSTANCE_ID:-}" =~ ^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$ ]]; then
    echo "MYSQL_CONCURRENCY_INSTANCE_ID must be a unique UUIDv4." >&2
    exit 1
fi

if [[ "${MYSQL_DATABASE:-}" != "fleet_concurrency_test" || "${MYSQL_USER:-}" != "fleet_concurrency" ]]; then
    echo "Disposable database or account identity is not exact." >&2
    exit 1
fi

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}" <<SQL
CREATE DATABASE fleet_concurrency_guard_test;

CREATE TABLE _concurrency_test_guard (
    id TINYINT UNSIGNED NOT NULL,
    instance_id CHAR(36) NOT NULL,
    server_uuid CHAR(36) NOT NULL,
    environment_name VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY concurrency_guard_instance_unique (instance_id)
) ENGINE=InnoDB;

INSERT INTO _concurrency_test_guard (id, instance_id, server_uuid, environment_name)
SELECT 1, '${MYSQL_CONCURRENCY_INSTANCE_ID}', @@server_uuid, 'mysql-concurrency-test';

RENAME TABLE _concurrency_test_guard TO fleet_concurrency_guard_test._concurrency_test_guard;
GRANT SELECT ON fleet_concurrency_guard_test._concurrency_test_guard TO 'fleet_concurrency'@'%';
SQL
