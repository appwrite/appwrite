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

export type PostgresConnectionStateRow = {
  state: string
  count: number
}

export type PostgresConnectionAppRow = {
  applicationName: string
  count: number
}

export type PostgresActiveConnectionRow = {
  pid: number
  isClientBackend: boolean
  backendType: string | null
  username: string | null
  database: string | null
  applicationName: string | null
  clientHost: string
  clientPort: number | null
  state: string | null
  waitEventType: string | null
  waitEvent: string | null
  backendStart: string | null
  queryStart: string | null
  stateChange: string | null
  query: string | null
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

/** Active queries running longer than this are highlighted as long-running. */
export const POSTGRES_LONG_RUNNING_QUERY_THRESHOLD_MS = 10_000

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
  if (!Number.isFinite(seconds) || seconds <= 0) return '-'

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

function parsePostgresBoolean(value: unknown): boolean {
  if (value === true) return true
  if (value === false || value == null) return false
  if (typeof value === 'number') return value !== 0
  const normalized = String(value).trim().toLowerCase()
  return (
    normalized === 't' ||
    normalized === 'true' ||
    normalized === '1' ||
    normalized === 'yes'
  )
}

function readPostgresMetricsRowString(
  row: Record<string, unknown>,
  ...keys: string[]
): string | null {
  for (const key of keys) {
    const value = row[key]
    if (value == null || value === '') continue
    const str = String(value).trim()
    if (str) return str
  }
  return null
}

function readPostgresMetricsRowBoolean(
  row: Record<string, unknown>,
  ...keys: string[]
): boolean | null {
  for (const key of keys) {
    if (!(key in row)) continue
    return parsePostgresBoolean(row[key])
  }
  return null
}

const CLIENT_SESSION_STATES = new Set([
  'active',
  'idle',
  'idle in transaction',
  'idle in transaction (aborted)',
  'fastpath function call',
  'disabled',
])

function normalizePostgresBackendType(
  backendType: string | null | undefined,
): string | null {
  if (!backendType?.trim()) return null
  return backendType.trim().toLowerCase()
}

function inferPostgresClientBackend(row: {
  backendType: string | null
  state: string | null
  username: string | null
  database: string | null
}): boolean {
  const normalizedType = normalizePostgresBackendType(row.backendType)
  if (normalizedType === POSTGRES_CLIENT_BACKEND_TYPE) return true
  if (normalizedType) return false

  const state = row.state?.trim().toLowerCase() ?? ''
  if (CLIENT_SESSION_STATES.has(state)) return true
  if (row.username?.trim() || row.database?.trim()) return true
  return false
}

export function parsePostgresActiveConnections(
  execution: Models.DedicatedDatabaseExecution,
): PostgresActiveConnectionRow[] {
  return executionResultRows<Record<string, unknown>>(execution).map((row) => {
    const clientPortRaw = row.client_port ?? row.clientPort
    const clientPort =
      clientPortRaw == null || clientPortRaw === ''
        ? null
        : toFiniteNumber(clientPortRaw, NaN)
    const backendType = readPostgresMetricsRowString(row, 'backend_type', 'backendType')
    const username = readPostgresMetricsRowString(row, 'username', 'usename')
    const database = readPostgresMetricsRowString(row, 'database', 'datname')
    const state = readPostgresMetricsRowString(row, 'state')
    const parsedIsClientBackend = readPostgresMetricsRowBoolean(
      row,
      'is_client_backend',
      'isClientBackend',
    )
    const isClientBackend =
      parsedIsClientBackend ??
      inferPostgresClientBackend({ backendType, state, username, database })

    return {
      pid: toFiniteNumber(row.pid ?? row.Pid),
      isClientBackend,
      backendType,
      username,
      database,
      applicationName: readPostgresMetricsRowString(
        row,
        'application_name',
        'applicationName',
      ),
      clientHost: readPostgresMetricsRowString(row, 'client_host', 'clientHost') ?? '',
      clientPort: Number.isFinite(clientPort) ? clientPort : null,
      state,
      waitEventType: readPostgresMetricsRowString(
        row,
        'wait_event_type',
        'waitEventType',
      ),
      waitEvent: readPostgresMetricsRowString(row, 'wait_event', 'waitEvent'),
      backendStart: readPostgresMetricsRowString(row, 'backend_start', 'backendStart'),
      queryStart: readPostgresMetricsRowString(row, 'query_start', 'queryStart'),
      stateChange: readPostgresMetricsRowString(row, 'state_change', 'stateChange'),
      query: readPostgresMetricsRowString(row, 'query'),
    }
  })
}

export const POSTGRES_CLIENT_BACKEND_TYPE = 'client backend'

export type PostgresConnectionBackendScope = 'clients' | 'backends'

export function isPostgresClientBackend(
  connection: Pick<PostgresActiveConnectionRow, 'isClientBackend'> &
    Partial<
      Pick<
        PostgresActiveConnectionRow,
        'backendType' | 'state' | 'username' | 'database'
      >
    >,
): boolean {
  if (typeof connection.isClientBackend === 'boolean') {
    return connection.isClientBackend
  }
  return inferPostgresClientBackend({
    backendType: connection.backendType ?? null,
    state: connection.state ?? null,
    username: connection.username ?? null,
    database: connection.database ?? null,
  })
}

export function matchesPostgresConnectionBackendScope(
  connection: PostgresActiveConnectionRow,
  scope: PostgresConnectionBackendScope,
): boolean {
  const isClient = isPostgresClientBackend(connection)
  return scope === 'clients' ? isClient : !isClient
}

export function formatPostgresBackendTypeLabel(
  backendType: string | null,
): string {
  const normalized = normalizePostgresBackendType(backendType)
  if (!normalized) return 'Unknown'
  switch (normalized) {
    case POSTGRES_CLIENT_BACKEND_TYPE:
      return 'Client'
    case 'background worker':
      return 'Background'
    case 'autovacuum worker':
      return 'Autovacuum'
    case 'parallel worker':
      return 'Parallel'
    case 'logical replication launcher':
      return 'Replication'
    default:
      return normalized
        .split(' ')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ')
  }
}

export function backendTypeBadgeVariant(
  backendType: string | null,
): 'success' | 'info' | 'warning' {
  if (normalizePostgresBackendType(backendType) === POSTGRES_CLIENT_BACKEND_TYPE) {
    return 'success'
  }
  if (normalizePostgresBackendType(backendType) === 'autovacuum worker') {
    return 'warning'
  }
  return 'info'
}

export function formatPostgresConnectionUsername(
  username: string | null,
  backendType: string | null,
): string {
  if (username?.trim()) return username.trim()
  if (!isPostgresClientBackend({ backendType })) return 'System'
  return '-'
}

export function formatPostgresConnectionDatabase(database: string | null): string {
  return database?.trim() || '-'
}

export function formatPostgresApplicationName(
  applicationName: string | null,
): string {
  return applicationName?.trim() || '-'
}

export function formatPostgresConnectionStateLabel(
  state: string | null,
  backendType: string | null,
): string {
  if (state?.trim()) return formatConnectionStateLabel(state.trim())
  if (!isPostgresClientBackend({ backendType })) return 'System'
  return '-'
}

export function formatPostgresClientAddress(
  clientHost: string,
  clientPort: number | null,
): string {
  if (!clientHost) return 'Local'
  return clientPort != null ? `${clientHost}:${clientPort}` : clientHost
}

export function connectionStateBadgeVariant(
  state: string | null,
  backendType: string | null = null,
): 'success' | 'info' | 'warning' | 'error' {
  if (!state?.trim()) {
    return isPostgresClientBackend({ backendType }) ? 'info' : 'info'
  }

  switch (state.toLowerCase()) {
    case 'active':
      return 'success'
    case 'idle':
      return 'info'
    case 'idle in transaction':
      return 'warning'
    case 'idle in transaction (aborted)':
      return 'error'
    default:
      return 'info'
  }
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

export type PostgresConnectionStateFilter =
  | 'all'
  | 'active'
  | 'idle'
  | 'idle in transaction'
  | 'long-running'

export function matchesPostgresConnectionStateFilter(
  connection: PostgresActiveConnectionRow,
  filter: PostgresConnectionStateFilter,
): boolean {
  if (filter === 'all') return true
  if (filter === 'long-running') {
    return isLongRunningConnection(connection)
  }
  if (filter === 'idle in transaction') {
    const state = connection.state?.toLowerCase() ?? ''
    return (
      state === 'idle in transaction' ||
      state === 'idle in transaction (aborted)'
    )
  }
  return connection.state?.toLowerCase() === filter
}

export function isLongRunningConnection(
  connection: Pick<
    PostgresActiveConnectionRow,
    'state' | 'queryStart' | 'backendType'
  >,
  thresholdMs = POSTGRES_LONG_RUNNING_QUERY_THRESHOLD_MS,
): boolean {
  if (!isPostgresClientBackend(connection)) return false
  if (connection.state?.toLowerCase() !== 'active') return false
  if (!connection.queryStart) return false
  const start = Date.parse(connection.queryStart)
  if (!Number.isFinite(start)) return false
  return Date.now() - start >= thresholdMs
}

export function formatPostgresDurationSince(isoDate: string | null): string {
  if (!isoDate) return '-'
  const parsed = Date.parse(isoDate)
  if (!Number.isFinite(parsed)) return '-'

  const totalSeconds = Math.max(0, Math.floor((Date.now() - parsed) / 1000))
  if (totalSeconds < 60) return `${totalSeconds}s`

  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  if (minutes < 60) {
    return seconds > 0 ? `${minutes}m ${seconds}s` : `${minutes}m`
  }

  const hours = Math.floor(minutes / 60)
  const remainingMinutes = minutes % 60
  return remainingMinutes > 0 ? `${hours}h ${remainingMinutes}m` : `${hours}h`
}

export function formatPostgresWaitEvent(
  waitEventType: string | null,
  waitEvent: string | null,
): string {
  if (!waitEventType && !waitEvent) return '-'
  if (waitEventType && waitEvent) return `${waitEventType} / ${waitEvent}`
  return waitEventType ?? waitEvent ?? '-'
}

export function serializePostgresActiveConnectionJson(
  connection: PostgresActiveConnectionRow,
): string {
  return JSON.stringify(
    {
      pid: connection.pid,
      backendType: connection.backendType,
      username: connection.username,
      database: connection.database,
      applicationName: connection.applicationName,
      clientHost: connection.clientHost,
      clientPort: connection.clientPort,
      clientAddress: formatPostgresClientAddress(
        connection.clientHost,
        connection.clientPort,
      ),
      state: connection.state,
      waitEventType: connection.waitEventType,
      waitEvent: connection.waitEvent,
      backendStart: connection.backendStart,
      queryStart: connection.queryStart,
      stateChange: connection.stateChange,
      query: connection.query,
      longRunning: isLongRunningConnection(connection),
    },
    null,
    2,
  )
}
