import type { Models } from '@appwrite.io/console'
import { executionResultRows } from '@/lib/postgres-sql'

export type PostgresMetricsSnapshotRow = {
  active_connections?: number | string
  xact_commit?: number | string
  xact_rollback?: number | string
  blks_read?: number | string
  blks_hit?: number | string
  tup_returned?: number | string
  tup_fetched?: number | string
  tup_inserted?: number | string
  tup_updated?: number | string
  tup_deleted?: number | string
  conflicts?: number | string
  deadlocks?: number | string
  temp_bytes?: number | string
  database_size_bytes?: number | string
  total_connections?: number | string
  active_queries?: number | string
  idle_in_transaction?: number | string
  long_running_queries?: number | string
  server_started_at?: string
  uptime_seconds?: number | string
}

export type PostgresMetricsSnapshot = {
  timestamp: number
  activeConnections: number
  totalConnections: number
  activeQueries: number
  idleInTransaction: number
  longRunningQueries: number
  xactCommit: number
  xactRollback: number
  blksRead: number
  blksHit: number
  tupReturned: number
  tupFetched: number
  tupInserted: number
  tupUpdated: number
  tupDeleted: number
  conflicts: number
  deadlocks: number
  tempBytes: number
  databaseSizeBytes: number
  cacheHitRatio: number
  uptimeSeconds: number
  serverStartedAt: number | null
}

export type PostgresMetricsSample = PostgresMetricsSnapshot & {
  transactionsPerMin: number
  commitsPerMin: number
  rollbacksPerMin: number
  tuplesReadPerMin: number
  tuplesWrittenPerMin: number
  blockReadsPerMin: number
}

export type PostgresConnectionStateRow = {
  state: string
  count: number
}

export type PostgresConnectionAppRow = {
  applicationName: string
  count: number
}

export type PostgresTableActivityRow = {
  schema: string
  tableName: string
  totalBytes: number
  liveTuples: number
  deadTuples: number
  seqScans: number
  idxScans: number
  writeOperations: number
  readOperations: number
}

const SAMPLE_STORAGE_PREFIX = 'console.postgresMetricsSamples.'
const MAX_STORED_SAMPLES = 2_880
const MIN_SAMPLE_INTERVAL_MS = 15_000

function toFiniteNumber(value: unknown, fallback = 0): number {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  const parsed = Number.parseFloat(String(value ?? fallback))
  return Number.isFinite(parsed) ? parsed : fallback
}

function parseServerStartedAt(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  if (typeof value === 'string' && value.trim()) {
    const parsed = Date.parse(value)
    return Number.isFinite(parsed) ? parsed : null
  }
  return null
}

/** Human-readable PostgreSQL server uptime from `pg_postmaster_start_time()`. */
export function formatPostgresUptime(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds <= 0) return '—'

  const days = Math.floor(seconds / 86_400)
  const hours = Math.floor((seconds % 86_400) / 3_600)
  const minutes = Math.floor((seconds % 3_600) / 60)

  if (days > 0) {
    return hours > 0 ? `${days}d ${hours}h` : `${days}d`
  }
  if (hours > 0) {
    return minutes > 0 ? `${hours}h ${minutes}m` : `${hours}h`
  }
  if (minutes > 0) return `${minutes}m`
  return '< 1m'
}

export function parsePostgresMetricsSnapshot(
  execution: Models.DedicatedDatabaseExecution,
  timestamp = Date.now(),
): PostgresMetricsSnapshot | null {
  const rows = executionResultRows<PostgresMetricsSnapshotRow>(execution)
  const row = rows[0]
  if (!row) return null

  const blksRead = toFiniteNumber(row.blks_read)
  const blksHit = toFiniteNumber(row.blks_hit)
  const blockTotal = blksRead + blksHit

  return {
    timestamp,
    activeConnections: toFiniteNumber(row.active_connections),
    totalConnections: toFiniteNumber(row.total_connections),
    activeQueries: toFiniteNumber(row.active_queries),
    idleInTransaction: toFiniteNumber(row.idle_in_transaction),
    longRunningQueries: toFiniteNumber(row.long_running_queries),
    xactCommit: toFiniteNumber(row.xact_commit),
    xactRollback: toFiniteNumber(row.xact_rollback),
    blksRead,
    blksHit,
    tupReturned: toFiniteNumber(row.tup_returned),
    tupFetched: toFiniteNumber(row.tup_fetched),
    tupInserted: toFiniteNumber(row.tup_inserted),
    tupUpdated: toFiniteNumber(row.tup_updated),
    tupDeleted: toFiniteNumber(row.tup_deleted),
    conflicts: toFiniteNumber(row.conflicts),
    deadlocks: toFiniteNumber(row.deadlocks),
    tempBytes: toFiniteNumber(row.temp_bytes),
    databaseSizeBytes: toFiniteNumber(row.database_size_bytes),
    cacheHitRatio: blockTotal > 0 ? (blksHit / blockTotal) * 100 : 100,
    uptimeSeconds: toFiniteNumber(row.uptime_seconds),
    serverStartedAt: parseServerStartedAt(row.server_started_at),
  }
}

export function parsePostgresConnectionStates(
  execution: Models.DedicatedDatabaseExecution,
): PostgresConnectionStateRow[] {
  return executionResultRows<{ state?: string; count?: number | string }>(
    execution,
  ).map((row) => ({
    state: String(row.state ?? 'unknown'),
    count: toFiniteNumber(row.count),
  }))
}

export function parsePostgresConnectionApps(
  execution: Models.DedicatedDatabaseExecution,
): PostgresConnectionAppRow[] {
  return executionResultRows<{
    application_name?: string
    count?: number | string
  }>(execution).map((row) => ({
    applicationName: String(row.application_name ?? 'unknown'),
    count: toFiniteNumber(row.count),
  }))
}

export function parsePostgresTableActivity(
  execution: Models.DedicatedDatabaseExecution,
): PostgresTableActivityRow[] {
  return executionResultRows<{
    schemaname?: string
    table_name?: string
    total_bytes?: number | string
    live_tuples?: number | string
    dead_tuples?: number | string
    seq_scans?: number | string
    idx_scans?: number | string
    write_operations?: number | string
    read_operations?: number | string
  }>(execution).map((row) => ({
    schema: String(row.schemaname ?? ''),
    tableName: String(row.table_name ?? ''),
    totalBytes: toFiniteNumber(row.total_bytes),
    liveTuples: toFiniteNumber(row.live_tuples),
    deadTuples: toFiniteNumber(row.dead_tuples),
    seqScans: toFiniteNumber(row.seq_scans),
    idxScans: toFiniteNumber(row.idx_scans),
    writeOperations: toFiniteNumber(row.write_operations),
    readOperations: toFiniteNumber(row.read_operations),
  }))
}

function perMinuteRate(
  current: number,
  previous: number,
  elapsedMs: number,
): number {
  if (elapsedMs < MIN_SAMPLE_INTERVAL_MS) return 0
  const delta = Math.max(0, current - previous)
  return (delta / elapsedMs) * 60_000
}

export function buildPostgresMetricsSample(
  snapshot: PostgresMetricsSnapshot,
  previous: PostgresMetricsSnapshot | null,
): PostgresMetricsSample {
  if (!previous) {
    return {
      ...snapshot,
      transactionsPerMin: 0,
      commitsPerMin: 0,
      rollbacksPerMin: 0,
      tuplesReadPerMin: 0,
      tuplesWrittenPerMin: 0,
      blockReadsPerMin: 0,
    }
  }

  const elapsedMs = snapshot.timestamp - previous.timestamp
  const commitsPerMin = perMinuteRate(
    snapshot.xactCommit,
    previous.xactCommit,
    elapsedMs,
  )
  const rollbacksPerMin = perMinuteRate(
    snapshot.xactRollback,
    previous.xactRollback,
    elapsedMs,
  )

  return {
    ...snapshot,
    transactionsPerMin: commitsPerMin + rollbacksPerMin,
    commitsPerMin,
    rollbacksPerMin,
    tuplesReadPerMin: perMinuteRate(
      snapshot.tupReturned + snapshot.tupFetched,
      previous.tupReturned + previous.tupFetched,
      elapsedMs,
    ),
    tuplesWrittenPerMin: perMinuteRate(
      snapshot.tupInserted + snapshot.tupUpdated + snapshot.tupDeleted,
      previous.tupInserted + previous.tupUpdated + previous.tupDeleted,
      elapsedMs,
    ),
    blockReadsPerMin: perMinuteRate(
      snapshot.blksRead,
      previous.blksRead,
      elapsedMs,
    ),
  }
}

function sampleStorageKey(databaseId: string): string {
  return `${SAMPLE_STORAGE_PREFIX}${databaseId}`
}

export function readPostgresMetricsSamples(
  databaseId: string,
): PostgresMetricsSample[] {
  if (typeof window === 'undefined' || !databaseId) return []
  try {
    const raw = sessionStorage.getItem(sampleStorageKey(databaseId))
    if (!raw) return []
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []
    return parsed
      .filter(
        (item): item is PostgresMetricsSample =>
          typeof item === 'object' &&
          item !== null &&
          typeof (item as PostgresMetricsSample).timestamp === 'number',
      )
      .sort((a, b) => a.timestamp - b.timestamp)
  } catch {
    return []
  }
}

export function writePostgresMetricsSamples(
  databaseId: string,
  samples: PostgresMetricsSample[],
): void {
  if (typeof window === 'undefined' || !databaseId) return
  try {
    const trimmed = samples.slice(-MAX_STORED_SAMPLES)
    sessionStorage.setItem(
      sampleStorageKey(databaseId),
      JSON.stringify(trimmed),
    )
  } catch {
    /* ignore quota errors */
  }
}

export function appendPostgresMetricsSample(
  databaseId: string,
  sample: PostgresMetricsSample,
): PostgresMetricsSample[] {
  const existing = readPostgresMetricsSamples(databaseId)
  const last = existing[existing.length - 1]
  if (last && sample.timestamp - last.timestamp < MIN_SAMPLE_INTERVAL_MS) {
    const next = [...existing.slice(0, -1), sample]
    writePostgresMetricsSamples(databaseId, next)
    return next
  }
  const next = [...existing, sample]
  writePostgresMetricsSamples(databaseId, next)
  return next
}

export function filterSamplesByRange(
  samples: PostgresMetricsSample[],
  fromMs: number,
  toMs: number,
): PostgresMetricsSample[] {
  return samples.filter(
    (sample) => sample.timestamp >= fromMs && sample.timestamp <= toMs,
  )
}

export function formatConnectionStateLabel(state: string): string {
  switch (state) {
    case 'active':
      return 'Active'
    case 'idle':
      return 'Idle'
    case 'idle in transaction':
      return 'Idle in transaction'
    case 'idle in transaction (aborted)':
      return 'Idle in transaction (aborted)'
    case 'fastpath function call':
      return 'Fastpath function call'
    case 'disabled':
      return 'Disabled'
    default:
      return state.charAt(0).toUpperCase() + state.slice(1)
  }
}
