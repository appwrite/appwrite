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
  # The named logs must match the ones clickhouse-config.xml removes: a log that
  # is still written gets replaced on the next restart if its TTL is altered here.
  client --format TSVRaw <<'SQL' | client --multiquery
SELECT concat('ALTER TABLE system.', stale.name, ' MODIFY TTL ', if(active.name = '', 'event_date + INTERVAL 7 DAY DELETE', splitByString(' SETTINGS ', splitByString(' TTL ', active.engine_full)[2])[1]), ';')
FROM (
  SELECT name, splitByChar('_', name)[-1] AS suffix, arrayStringConcat(arrayPopBack(splitByChar('_', name)), '_') AS log
  FROM system.tables
  WHERE database = 'system'
    AND position(engine_full, ' TTL ') = 0
    AND endsWith(engine, 'MergeTree')
    AND name IN (SELECT table FROM system.columns WHERE database = 'system' AND name = 'event_date' AND type = 'Date')
) AS stale
LEFT JOIN system.tables AS active
  ON active.database = 'system'
  AND active.name = stale.log
  AND position(active.engine_full, ' TTL ') > 0
WHERE (endsWith(stale.log, '_log') AND toUInt64OrNull(stale.suffix) IS NOT NULL)
  OR stale.name IN ('asynchronous_metric_log', 'metric_log', 'histogram_metric_log', 'query_metric_log', 'processors_profile_log', 'trace_log', 'text_log')
SQL
)
