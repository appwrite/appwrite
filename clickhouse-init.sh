#!/bin/bash
# The ClickHouse entrypoint sources this file, so a subshell keeps these options out of it.
(
  set -euo pipefail

  client() {
    clickhouse-client --host 127.0.0.1 --user "$CLICKHOUSE_USER" --password "$CLICKHOUSE_PASSWORD" "$@"
  }

  # Flushing replaces log tables whose definition changed, keeping each old table as <log>_<N>.
  client --query 'SYSTEM FLUSH LOGS'

  # Old tables never expire, so give each one the TTL of the log that replaced it.
  client --format TSVRaw <<'SQL' | client --multiquery
SELECT concat('ALTER TABLE system.', retired.name, ' MODIFY TTL ', extract(active.engine_full, ' TTL (.+) SETTINGS '), ';')
FROM system.tables AS retired
INNER JOIN system.tables AS active ON active.name = replaceRegexpOne(retired.name, '_[0-9]+$', '')
WHERE retired.database = 'system'
  AND active.database = 'system'
  AND match(retired.name, '_log_[0-9]+$')
  AND position(retired.engine_full, ' TTL ') = 0
  AND position(active.engine_full, ' TTL ') > 0
SQL
)
