#!/bin/bash
# The ClickHouse entrypoint sources this file, so a subshell keeps these options out of it.
(
  set -euo pipefail

  client() {
    clickhouse-client --host 127.0.0.1 --user "$CLICKHOUSE_USER" --password "$CLICKHOUSE_PASSWORD" "$@"
  }

  # Flushing replaces log tables whose definition changed, keeping each old table as <log>_<N>.
  client --query 'SYSTEM FLUSH LOGS'

  # Tables left behind never expire on their own: take the TTL of the log that
  # replaced this one, or a week when the log is no longer written at all.
  # Patterns rather than fixed substrings: the <N> suffix differs per table, and the
  # TTL expression sits between two markers inside engine_full.
  client --format TSVRaw <<'SQL' | client --multiquery
SELECT concat('ALTER TABLE system.', stale.name, ' MODIFY TTL ', if(active.name = '', 'event_date + INTERVAL 7 DAY DELETE', extract(active.engine_full, ' TTL (.+) SETTINGS ')), ';')
FROM system.tables AS stale
LEFT JOIN system.tables AS active
  ON active.database = 'system'
  AND active.name = replaceRegexpOne(stale.name, '_[0-9]+$', '')
  AND position(active.engine_full, ' TTL ') > 0
WHERE stale.database = 'system'
  AND match(stale.name, '_log(_[0-9]+)?$')
  AND position(stale.engine_full, ' TTL ') = 0
  AND endsWith(stale.engine, 'MergeTree')
  AND stale.name IN (SELECT table FROM system.columns WHERE database = 'system' AND name = 'event_date' AND type = 'Date')
SQL
)
