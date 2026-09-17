# ClickHouse system log retention

The bundled ClickHouse service limits diagnostic logs independently of Appwrite's usage and execution data:

| Logs | Retention |
| --- | --- |
| `text_log`, `trace_log` | 3 days |
| `error_log` | 14 days |
| `asynchronous_metric_log`, `metric_log`, `part_log`, `processors_profile_log`, `query_log`, `histogram_metric_log`, `query_views_log`, `query_metric_log`, `background_schedule_pool_log`, `asynchronous_insert_log` | 7 days |

The Compose file embeds the server configuration and a startup migration, so installations do not need additional files. Inline configs require Docker Compose 2.23.1 or later.

For an existing installation, update the Compose file and recreate ClickHouse while keeping its volume:

```sh
docker compose up -d --no-deps --force-recreate clickhouse
```

Each startup applies TTLs to recognized system log tables that do not have one, including historical tables with numeric suffixes such as `query_log_0`. Existing TTLs on historical tables are preserved. Missing optional log tables are skipped. No tables are truncated, and Appwrite usage and execution tables are unchanged. Expired rows are removed asynchronously by ClickHouse, so disk space is not reclaimed immediately.

Retention belongs to ClickHouse provisioning: `usage-setup` continues to set up Appwrite's schemas. A SQL-only fix there would be lost when ClickHouse recreates system logs from its server configuration. External ClickHouse deployments should configure their own system log TTLs; these settings apply only to the bundled service. See the [ClickHouse system log implementation](https://github.com/ClickHouse/ClickHouse/blob/v26.4.3.37-stable/src/Interpreters/SystemLog.cpp).

Run the isolated fresh-install, upgrade, and restart regression test from the repository root:

```sh
bash tests/e2e/Services/Usage/clickhouse-retention.sh
```

The test uses its own Compose project and volume and removes them when it finishes.
