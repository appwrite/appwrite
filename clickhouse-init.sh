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
SELECT concat('ALTER TABLE system.', retired.name, ' MODIFY TTL ', splitByString(' SETTINGS ', splitByString(' TTL ', active.engine_full)[2])[1], ';')
FROM (
  SELECT name, splitByChar('_', name)[-1] AS suffix, arrayStringConcat(arrayPopBack(splitByChar('_', name)), '_') AS log
  FROM system.tables
  WHERE database = 'system' AND position(engine_full, ' TTL ') = 0
) AS retired
INNER JOIN system.tables AS active ON active.name = retired.log
WHERE active.database = 'system'
  AND endsWith(retired.log, '_log')
  AND toUInt64OrNull(retired.suffix) IS NOT NULL
  AND position(active.engine_full, ' TTL ') > 0
SQL
)
