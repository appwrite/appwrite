#!/usr/bin/env bash
# Run from the host with Docker Compose and Python 3; uses its own disposable volume.
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/../../../.." && pwd)"
test_dir="$(mktemp -d)"
project="appwrite-retention-$(date +%s)-$$"
compose_file="${test_dir}/fresh.json"

compose() {
    docker compose --project-name "$project" --file "$compose_file" "$@"
}

cleanup() {
    status=$?
    trap - EXIT
    if [ -f "$compose_file" ]; then
        if [ "$status" -ne 0 ]; then compose logs --no-color || true; fi
        compose down --volumes --remove-orphans || true
    fi
    rm -rf "$test_dir"
    exit "$status"
}
trap cleanup EXIT

docker compose --file "${repo_root}/docker-compose.yml" config --format json > "${test_dir}/source.json"
python3 - "$test_dir" <<'PY'
import copy
import json
import pathlib
import sys
import xml.etree.ElementTree as ET

directory = pathlib.Path(sys.argv[1])
source = json.loads((directory / "source.json").read_text())
service = copy.deepcopy(source["services"]["clickhouse"])
service.pop("container_name", None)
service.pop("networks", None)
service["restart"] = "no"
service["environment"]["CLICKHOUSE_PASSWORD"] = "retention-test"
service["volumes"] = [{"type": "volume", "source": "data", "target": "/var/lib/clickhouse"}]
configs = {
    item["source"]: {"content": source["configs"][item["source"]]["content"]}
    for item in service["configs"]
}

# Check every configured log, including those that only materialize on demand.
expected = dict.fromkeys([
    "asynchronous_metric_log", "metric_log", "part_log", "processors_profile_log",
    "query_log", "histogram_metric_log", "query_views_log", "query_metric_log",
    "background_schedule_pool_log", "asynchronous_insert_log",
], 7)
expected.update(text_log=3, trace_log=3, error_log=14)
xml = ET.fromstring(configs["clickhouse-logs.xml"]["content"])
for name, days in expected.items():
    assert xml.findtext(f"{name}/ttl") == f"event_date + INTERVAL {days} DAY DELETE", name

fresh = {"services": {"clickhouse": service}, "configs": configs, "volumes": {"data": {}}}
(directory / "fresh.json").write_text(json.dumps(fresh))

# Keep one optional log absent when exercising the existing-volume migration.
upgrade = copy.deepcopy(fresh)
upgrade["configs"]["retention-test.xml"] = {
    "content": '<clickhouse><processors_profile_log remove="1"/></clickhouse>'
}
disabled_log = {
    "source": "retention-test.xml",
    "target": "/etc/clickhouse-server/config.d/z-retention-test.xml",
}
upgrade["services"]["clickhouse"]["configs"].append(disabled_log)
(directory / "upgrade.json").write_text(json.dumps(upgrade))

baseline = copy.deepcopy(upgrade)
baseline["services"]["clickhouse"]["environment"].pop("CLICKHOUSE_ALWAYS_RUN_INITDB_SCRIPTS")
baseline["services"]["clickhouse"]["configs"] = [disabled_log]
baseline["configs"] = {"retention-test.xml": upgrade["configs"]["retention-test.xml"]}
(directory / "baseline.json").write_text(json.dumps(baseline))
PY

query() {
    compose exec -T clickhouse clickhouse-client --user appwrite --password retention-test --multiquery --query "$1"
}

start() {
    compose up --detach --wait --wait-timeout 120 "$@"
    # The entrypoint's temporary server can pass the healthcheck before init finishes.
    local deadline=$((SECONDS + 120))
    until compose exec -T clickhouse bash -c '[[ "$(cat /proc/1/comm)" == clickhouse-serv ]] && clickhouse-client --user appwrite --password retention-test --query "SELECT 1" >/dev/null'; do
        if [ "$SECONDS" -ge "$deadline" ]; then
            printf 'ClickHouse initialization did not finish within 120 seconds\n' >&2
            exit 1
        fi
    done
}

assert_query() {
    local actual
    actual="$(query "$1")"
    if [ "$actual" != "$2" ]; then
        printf 'Expected %s, got %s for: %s\n' "$2" "$actual" "$1" >&2
        exit 1
    fi
}

log_filter="database = 'system' AND match(name, '^(text|trace|asynchronous_metric|metric|part|processors_profile|query|histogram_metric|query_views|query_metric|background_schedule_pool|asynchronous_insert|error)_log(_[0-9]+)?$')"

check_ttls() {
    query 'SYSTEM FLUSH LOGS'
    assert_query "SELECT count() > 0 FROM system.tables WHERE $log_filter" 1
    assert_query "SELECT count() FROM system.tables WHERE $log_filter AND position(create_table_query, ' TTL ') = 0" 0
    assert_query "SELECT count() FROM system.tables WHERE $log_filter AND (NOT match(name, '_[0-9]+$') OR name IN ('trace_log_99', 'part_log_99', 'error_log_99')) AND position(replaceAll(create_table_query, '\`', ''), concat(' TTL event_date + toIntervalDay(', toString(multiIf(match(name, '^(text|trace)_log(_[0-9]+)?$'), 3, match(name, '^error_log(_[0-9]+)?$'), 14, 7)), ')')) = 0" 0
}

snapshot() {
    query "SELECT name, uuid FROM system.tables WHERE $log_filter ORDER BY name" > "$1"
}

check_restart() {
    snapshot "${test_dir}/before.tsv"
    compose restart clickhouse
    start
    check_ttls
    snapshot "${test_dir}/after.tsv"
    python3 - "$test_dir" <<'PY'
import pathlib
import sys

directory = pathlib.Path(sys.argv[1])
before = dict(line.split('\t') for line in (directory / "before.tsv").read_text().splitlines())
after = dict(line.split('\t') for line in (directory / "after.tsv").read_text().splitlines())
assert all(after.get(name) == uuid for name, uuid in before.items()), "Restart replaced log tables"
PY
}

printf 'Checking fresh installation and restart\n'
start
check_ttls
check_restart
compose down --volumes

printf 'Preparing an existing volume without retention\n'
compose_file="${test_dir}/baseline.json"
start
query 'SYSTEM FLUSH LOGS'
assert_query 'EXISTS TABLE system.processors_profile_log' 0
assert_query "SELECT position(create_table_query, ' TTL ') FROM system.tables WHERE database = 'system' AND name = 'query_log'" 0
query "CREATE TABLE appwrite.retention_sentinel (value String) ENGINE = MergeTree ORDER BY tuple(); INSERT INTO appwrite.retention_sentinel VALUES ('keep'); INSERT INTO system.query_log (event_date, event_time, query_id) VALUES (today(), now(), 'appwrite-retention-sentinel')"
query 'CREATE TABLE system.metric_log_98 (event_date Date) ENGINE = MergeTree ORDER BY event_date TTL event_date + INTERVAL 21 DAY DELETE; INSERT INTO system.metric_log_98 VALUES (today())'
for name in trace part error; do
    query "CREATE TABLE system.${name}_log_99 (event_date Date, message String) ENGINE = MergeTree ORDER BY event_date; INSERT INTO system.${name}_log_99 VALUES (today(), 'keep'), (today() - 30, 'expire')"
done

printf 'Checking existing-volume migration and repeated execution\n'
compose_file="${test_dir}/upgrade.json"
start --force-recreate
check_ttls
assert_query 'EXISTS TABLE system.processors_profile_log' 0

# ALTER TTL schedules mutations; wait for their observable result without a fixed sleep.
deadline=$((SECONDS + 60))
while [ "$(query "SELECT count() FROM merge('system', '^(trace|part|error)_log_99$') WHERE message = 'expire'")" != 0 ]; do
    if [ "$SECONDS" -ge "$deadline" ]; then
        printf 'Expired fixture rows were not removed within 60 seconds\n' >&2
        exit 1
    fi
done

compose exec -T clickhouse bash /docker-entrypoint-initdb.d/appwrite-logs.sh
check_ttls
check_restart
assert_query 'SELECT value FROM appwrite.retention_sentinel' keep
assert_query "SELECT position(create_table_query, ' TTL event_date + toIntervalDay(21)') > 0 FROM system.tables WHERE database = 'system' AND name = 'metric_log_98'" 1
assert_query 'SELECT count() FROM system.metric_log_98' 1
assert_query "SELECT count() FROM merge('system', '^(trace|part|error)_log_99$') WHERE message = 'keep'" 3
assert_query "SELECT count() FROM merge('system', '^query_log(_[0-9]+)?$') WHERE query_id = 'appwrite-retention-sentinel'" 1
printf 'ClickHouse log retention checks passed\n'
