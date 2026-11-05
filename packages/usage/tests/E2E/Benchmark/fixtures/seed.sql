-- Synthetic event-row seed for the usage benchmark harness.
--
-- Placeholders (replaced by BenchmarkBase::seedEventRows):
--   {TABLE}   — fully-qualified events table reference (e.g. `db`.`utopia_usage_bench_events`)
--   {ROWS}    — number of rows to insert
--   {METRIC}  — metric name (e.g. network.requests)
--   {TENANT}  — tenant identifier
--
-- Rationale:
--   * 1000 distinct paths (api-style surface) — bounded but high-cardinality.
--   * 6 HTTP methods, 6 status codes — bounded cross-product (~36 keys).
--   * 30-day span, 1-minute resolution — matches expected production density.
--   * Country / service / resourceType cycled for routing-fallback tests.
INSERT INTO {TABLE}
            (id, metric, value, time, path, method, status, service, country,
             region, hostname, resourceType, resourceId, tenant)
SELECT
    lower(hex(randomString(16))) AS id,
    '{METRIC}'                     AS metric,
    intDiv(number, 1) + 1          AS value,
    now() - toIntervalSecond(intDiv(number * 86400 * 30, {ROWS})) AS time,
    concat('/v1/route/', toString(number % 1000))                AS path,
    [
      'GET','POST','PUT','PATCH','DELETE','OPTIONS'
    ][1 + (number % 6)]            AS method,
    [
      '200','201','204','400','404','500'
    ][1 + (number % 6)]            AS status,
    [
      'storage','databases','functions','users','teams','health'
    ][1 + (number % 6)]            AS service,
    [
      'us','de','fr','jp','br','in','gb','au','ca','sg'
    ][1 + (number % 10)]           AS country,
    [
      'us-east','eu-west','ap-south','sa-east'
    ][1 + (number % 4)]            AS region,
    concat('host-', toString(number % 10), '.example.com') AS hostname,
    [
      'project','bucket','database','function'
    ][1 + (number % 4)]            AS resourceType,
    concat('resource-', toString(number % 5000)) AS resourceId,
    '{TENANT}'                     AS tenant
FROM numbers({ROWS});
