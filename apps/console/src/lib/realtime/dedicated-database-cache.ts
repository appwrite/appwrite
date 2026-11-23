/**
 * React Query cache updates for dedicated database console realtime events.
 *
 * Event shapes (backend):
 * - `{engine}.{databaseId}.create|update|delete`
 * - `{engine}.{databaseId}.backups.{backupId}.create|update|delete`
 * - `{engine}.{databaseId}.restorations.{restorationId}.create|update|delete`
 * - `{engine}.{databaseId}.branches.{branchId}.create|delete`
 * - `{engine}.{databaseId}.extensions.{extensionId}.create|delete`
 * - `policies.{policyId}.create|update|delete`
 *
 * List caches (backups, policies, restorations) are always invalidated and
 * reloaded from the API. Realtime payloads are often partial; never prepend or
 * insert stub rows into paginated lists.
 */

import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import {
  isMongoEngine,
  isMysqlEngine,
  isPostgresEngine,
} from '@/lib/databases/native-database-engines'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import { backupResourceTypeForDbKind } from '@/lib/react-query/hooks/backups'
import {
  mergeMigrationPayloadIntoCache,
  migrationStatusFromRestorationStatus,
} from './migration-cache'

/** Engine / API prefixes emitted on the console realtime channel. */
export const DEDICATED_DATABASE_REALTIME_ENGINES = [
  'postgresql',
  'postgres',
  'mysql',
  'mariadb',
  'mongodb',
  'mongo',
  'tablesdb',
  'documentsdb',
  'vectorsdb',
] as const

const ENGINE_PREFIX_PATTERN = new RegExp(
  `^(${DEDICATED_DATABASE_REALTIME_ENGINES.join('|')})\\.([^.]+)(?:\\.(.*))?$`,
)

export type ParsedDedicatedDatabaseEvent = {
  engine: string
  databaseId: string
  resource?: 'backups' | 'restorations' | 'branches' | 'extensions'
  resourceId?: string
  action?: 'create' | 'update' | 'delete'
}

export type ParsedPolicyEvent = {
  policyId: string
  action: 'create' | 'update' | 'delete'
}

type ResourceAction = 'create' | 'update' | 'delete'

function normalizeEngine(engine: string): string {
  const key = engine.toLowerCase().trim()
  if (key === 'postgres') return 'postgresql'
  if (key === 'mongo') return 'mongodb'
  if (key === 'mariadb') return 'mysql'
  return key
}

/** Canonical realtime engine prefix for waits, cache keys, and event matching. */
export function normalizeDedicatedRealtimeEngine(engine: string): string {
  return normalizeEngine(engine)
}

const PRODUCT_API_TO_COMPUTE_ENGINE: Record<string, string> = {
  vectorsdb: 'postgresql',
  documentsdb: 'mongodb',
  tablesdb: 'mysql',
}

const COMPUTE_ENGINE_SHORTHANDS: Record<string, readonly string[]> = {
  postgresql: ['postgres'],
  mongodb: ['mongo'],
  mysql: ['mariadb'],
}

function addComputeEngineAliases(
  aliases: Set<string>,
  computeEngine: string,
): void {
  const normalized = normalizeEngine(computeEngine)
  aliases.add(normalized)
  for (const shorthand of COMPUTE_ENGINE_SHORTHANDS[normalized] ?? []) {
    aliases.add(shorthand)
  }
}

/**
 * Realtime `{engine}` prefixes that refer to the same dedicated database.
 * Product APIs (vectorsdb, documentsdb, tablesdb) may emit events on their
 * compute engine prefix (postgresql, mongodb, mysql) instead.
 */
export function resolveDedicatedRealtimeEngineAliases(engine: string): string[] {
  const normalized = normalizeEngine(engine)
  const aliases = new Set<string>([normalized, engine.toLowerCase().trim()])

  addComputeEngineAliases(aliases, normalized)

  for (const [productApi, computeEngine] of Object.entries(
    PRODUCT_API_TO_COMPUTE_ENGINE,
  )) {
    const compute = normalizeEngine(computeEngine)
    if (normalized === productApi) {
      addComputeEngineAliases(aliases, compute)
      continue
    }
    if (normalized === compute || aliases.has(compute)) {
      aliases.add(productApi)
    }
  }

  return [...aliases]
}

export function isDedicatedDatabaseLifecycleEvent(
  parsed: ParsedDedicatedDatabaseEvent,
): boolean {
  return (
    !parsed.resource &&
    (parsed.action === 'create' ||
      parsed.action === 'update' ||
      parsed.action === 'delete')
  )
}

export function findDedicatedDatabaseScopedEvent(
  events: string[],
  databaseId: string,
  engineAliases: string[],
  options?: { lifecycleOnly?: boolean },
): ParsedDedicatedDatabaseEvent | null {
  const aliasSet = new Set(engineAliases.map(normalizeEngine))
  let fallback: ParsedDedicatedDatabaseEvent | null = null

  for (const event of events) {
    const parsed = parseDedicatedDatabaseEvent(event)
    if (!parsed || parsed.databaseId !== databaseId) continue
    if (!aliasSet.has(normalizeEngine(parsed.engine))) continue

    if (isDedicatedDatabaseLifecycleEvent(parsed)) {
      return parsed
    }
    if (!fallback) fallback = parsed
  }

  return options?.lifecycleOnly ? null : fallback
}

function engineToProductRouteKind(engine: string): DatabaseRouteKind | null {
  const key = normalizeEngine(engine)
  if (key === 'tablesdb') return 'tablesdb'
  if (key === 'documentsdb') return 'documentsdb'
  if (key === 'vectorsdb') return 'vectorsdb'
  return null
}

function resolveProductRouteKindsForPayload(
  payload: Record<string, unknown>,
  engine?: string,
): DatabaseRouteKind[] {
  const api =
    typeof payload.api === 'string' ? payload.api.trim().toLowerCase() : ''
  if (api === 'vectorsdb' || api === 'documentsdb' || api === 'tablesdb') {
    return [api]
  }

  const fromEngine = engine ? engineToProductRouteKind(engine) : null
  return fromEngine ? [fromEngine] : []
}

function isDedicatedDatabaseEngineEvent(event: string): boolean {
  return ENGINE_PREFIX_PATTERN.test(event)
}

function isPolicyEvent(event: string): boolean {
  return /^policies\.([^.]+)\.(create|update|delete)$/.test(event)
}

/** True when any event targets a dedicated database or backup policy. */
export function isDedicatedDatabaseRealtimeSignal(events: string[]): boolean {
  return events.some(
    (event) => isDedicatedDatabaseEngineEvent(event) || isPolicyEvent(event),
  )
}

export function parseDedicatedDatabaseEvent(
  event: string,
): ParsedDedicatedDatabaseEvent | null {
  const match = event.match(ENGINE_PREFIX_PATTERN)
  if (!match) return null

  const engine = normalizeEngine(match[1] ?? '')
  const databaseId = match[2]
  const rest = match[3]
  if (!databaseId) return null

  if (!rest) return { engine, databaseId }

  const segments = rest.split('.')
  const action = segments.at(-1) as ParsedDedicatedDatabaseEvent['action']
  if (action !== 'create' && action !== 'update' && action !== 'delete') {
    return { engine, databaseId }
  }

  if (segments.length === 1) {
    return { engine, databaseId, action }
  }

  const resource = segments[0] as ParsedDedicatedDatabaseEvent['resource']
  if (
    resource !== 'backups' &&
    resource !== 'restorations' &&
    resource !== 'branches' &&
    resource !== 'extensions'
  ) {
    return { engine, databaseId, action }
  }

  const resourceId = segments[1]
  return { engine, databaseId, resource, resourceId, action }
}

export function parsePolicyEvent(event: string): ParsedPolicyEvent | null {
  const match = event.match(/^policies\.([^.]+)\.(create|update|delete)$/)
  if (!match?.[1] || !match[2]) return null
  return {
    policyId: match[1],
    action: match[2] as ParsedPolicyEvent['action'],
  }
}

function patchRecord<T extends Record<string, unknown>>(
  current: T | null | undefined,
  payload: Record<string, unknown>,
): T {
  if (!current) return payload as T
  return { ...current, ...payload }
}

/** True when a realtime payload targets the database itself, not a nested resource. */
function isDatabaseLifecyclePayload(
  payload: Record<string, unknown>,
  databaseId: string,
  options?: { messageHasNestedResourceEvent?: boolean },
): boolean {
  const payloadId =
    typeof payload.$id === 'string' ? payload.$id.trim() : undefined

  // Nested resources (backup, restoration, etc.) carry their own $id.
  if (payloadId && payloadId !== databaseId) {
    return false
  }

  // Backup / restoration / policy payloads must not patch database status.
  if (typeof payload.policyId === 'string') return false
  if (typeof payload.backupId === 'string') return false
  if (typeof payload.migrationId === 'string') return false
  if (payload.type === 'backup' || payload.type === 'pitr') return false

  // When nested resource events share this payload, require an explicit database $id.
  if (options?.messageHasNestedResourceEvent && !payloadId) {
    return false
  }

  return true
}

function patchDedicatedDatabaseListEntry(
  databases: Models.DedicatedDatabase[],
  databaseId: string,
  payload: Record<string, unknown>,
): { databases: Models.DedicatedDatabase[]; changed: boolean } {
  let changed = false
  const next = databases.map((db) => {
    if (db.$id !== databaseId) return db
    changed = true
    return { ...db, ...payload } as Models.DedicatedDatabase
  })
  return { databases: next, changed }
}

function invalidateQueryKey(
  queryClient: QueryClient,
  queryKey: readonly unknown[],
): void {
  void queryClient.invalidateQueries({ queryKey, exact: false })
}

function isTerminalBackupStatus(status: unknown): boolean {
  return status === 'completed' || status === 'verified' || status === 'failed'
}

/**
 * Merge a database payload into list + detail caches for the current project.
 * Detail caches only: patches an existing database row, never prepends list items.
 */
export function mergeDedicatedDatabasePayloadIntoCache(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  payload: Record<string, unknown>,
  engine?: string,
): void {
  if (!isDatabaseLifecyclePayload(payload, databaseId)) return

  queryClient.setQueryData(
    ['dedicated-databases', 'project', projectId],
    (
      prev:
        | { databases: Models.DedicatedDatabase[]; total: number }
        | undefined,
    ) => {
      if (!prev?.databases?.length) return prev
      const { databases, changed } = patchDedicatedDatabaseListEntry(
        prev.databases,
        databaseId,
        payload,
      )
      return changed ? { ...prev, databases } : prev
    },
  )

  if (engine && isMysqlEngine(engine)) {
    queryClient.setQueryData(
      ['mysql-database', 'project', projectId, databaseId],
      (prev: unknown) =>
        prev && typeof prev === 'object'
          ? patchRecord(prev as Record<string, unknown>, payload)
          : prev,
    )
  }

  if (engine && isPostgresEngine(engine)) {
    queryClient.setQueryData(
      ['postgres-database', 'project', projectId, databaseId],
      (prev: unknown) =>
        prev && typeof prev === 'object'
          ? patchRecord(prev as Record<string, unknown>, payload)
          : prev,
    )
  }

  if (engine && isMongoEngine(engine)) {
    queryClient.setQueryData(
      ['mongo-database', 'project', projectId, databaseId],
      (prev: unknown) =>
        prev && typeof prev === 'object'
          ? patchRecord(prev as Record<string, unknown>, payload)
          : prev,
    )
  }

  const productKinds = resolveProductRouteKindsForPayload(payload, engine)

  for (const dbKind of productKinds) {
    queryClient.setQueryData(
      ['database', 'project', projectId, databaseId, dbKind],
      (prev: unknown) =>
        prev && typeof prev === 'object'
          ? patchRecord(prev as Record<string, unknown>, payload)
          : prev,
    )
  }

  queryClient.setQueriesData(
    {
      queryKey: ['dedicated-database', 'project', projectId, databaseId],
      exact: false,
    },
    (prev: unknown) =>
      prev && typeof prev === 'object'
        ? patchRecord(prev as Record<string, unknown>, payload)
        : prev,
  )
}

function invalidateBackupListCaches(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  engine: string,
): void {
  const productKind = engineToProductRouteKind(engine)
  const nativeListKey = isMysqlEngine(engine)
    ? 'mysql-backups'
    : isPostgresEngine(engine)
      ? 'postgres-backups'
      : null

  if (nativeListKey) {
    invalidateQueryKey(queryClient, [
      nativeListKey,
      'project',
      projectId,
      databaseId,
    ])
  }
  if (productKind) {
    invalidateQueryKey(queryClient, [
      'backup-archives',
      'project',
      projectId,
      'database',
      databaseId,
    ])
  }
}

function mergeBackupPayloadIntoCache(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  _backupId: string,
  payload: Record<string, unknown>,
  engine: string,
  action: ResourceAction | undefined,
): void {
  const normalizedEngine = normalizeEngine(engine)
  const productKind = engineToProductRouteKind(engine)

  invalidateBackupListCaches(queryClient, projectId, databaseId, engine)

  if (action === 'update' && isTerminalBackupStatus(payload.status)) {
    invalidateQueryKey(queryClient, [
      'dedicated-backup-policies',
      'project',
      projectId,
      databaseId,
      normalizedEngine,
    ])
    if (productKind) {
      invalidateQueryKey(queryClient, [
        'backup-policies',
        'project',
        projectId,
        'database',
        databaseId,
        backupResourceTypeForDbKind(productKind),
      ])
    }
  }
}

function syncMigrationFromRestorationPayload(
  queryClient: QueryClient,
  projectId: string,
  payload: Record<string, unknown>,
): void {
  const migrationId = payload.migrationId
  if (typeof migrationId !== 'string' || !migrationId) return

  const mappedStatus = migrationStatusFromRestorationStatus(payload.status)
  const migrationPatch: Record<string, unknown> = { $id: migrationId }
  if (mappedStatus) migrationPatch.status = mappedStatus
  if (Array.isArray(payload.resources)) {
    migrationPatch.resources = payload.resources
  }
  if (payload.errors !== undefined) {
    migrationPatch.errors = payload.errors
  }

  mergeMigrationPayloadIntoCache(queryClient, projectId, migrationPatch)
}

function invalidateRestorationListCaches(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
): void {
  invalidateQueryKey(queryClient, [
    'restorations',
    'project',
    projectId,
    'database',
    databaseId,
  ])
  invalidateQueryKey(queryClient, [
    'dedicated-restorations',
    'project',
    projectId,
    databaseId,
  ])
  invalidateQueryKey(queryClient, [
    'dedicated-pitr-windows',
    'project',
    projectId,
    databaseId,
  ])
}

function mergeRestorationPayloadIntoCache(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  _restorationId: string,
  payload: Record<string, unknown>,
  _action: ResourceAction | undefined,
): void {
  syncMigrationFromRestorationPayload(queryClient, projectId, payload)

  invalidateRestorationListCaches(queryClient, projectId, databaseId)

  const mappedStatus = migrationStatusFromRestorationStatus(payload.status)
  const isTerminal =
    mappedStatus === 'completed' ||
    mappedStatus === 'failed' ||
    payload.status === 'deleted'

  if (isTerminal) {
    invalidateQueryKey(queryClient, [
      'backup-archives',
      'project',
      projectId,
      'database',
      databaseId,
    ])
    invalidateQueryKey(queryClient, ['databases', 'project', projectId])
  }
}

function invalidatePolicyListCaches(
  queryClient: QueryClient,
  projectId: string,
  databaseId?: string,
  engine?: string,
): void {
  if (databaseId) {
    invalidateQueryKey(queryClient, [
      'backup-policies',
      'project',
      projectId,
      'database',
      databaseId,
    ])
    invalidateQueryKey(queryClient, [
      'dedicated-backup-policies',
      'project',
      projectId,
      databaseId,
      ...(engine ? [normalizeEngine(engine)] : []),
    ])
    return
  }

  invalidateQueryKey(queryClient, ['backup-policies', 'project', projectId])
  invalidateQueryKey(queryClient, [
    'dedicated-backup-policies',
    'project',
    projectId,
  ])
}

function mergePolicyPayloadIntoCache(
  queryClient: QueryClient,
  projectId: string,
  _policyId: string,
  payload: Record<string, unknown>,
  _action: ResourceAction | undefined,
): void {
  const databaseId =
    (typeof payload.resourceId === 'string' && payload.resourceId) ||
    (typeof payload.databaseId === 'string' && payload.databaseId) ||
    undefined
  const engine =
    (typeof payload.engine === 'string' && payload.engine) || undefined

  invalidatePolicyListCaches(queryClient, projectId, databaseId, engine)
}

function invalidateExtensionCaches(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
): void {
  invalidateQueryKey(queryClient, [
    'postgres-database-extensions',
    'project',
    projectId,
    databaseId,
  ])
}

function invalidateNativeDatabaseDetailCaches(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
): void {
  for (const queryKey of [
    ['postgres-database', 'project', projectId, databaseId],
    ['mysql-database', 'project', projectId, databaseId],
    ['mongo-database', 'project', projectId, databaseId],
  ] as const) {
    invalidateQueryKey(queryClient, queryKey)
  }
}

function invalidateReplicaCaches(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
): void {
  invalidateQueryKey(queryClient, [
    'dedicated-database-replicas',
    'project',
    projectId,
    databaseId,
  ])
}

export function handleDedicatedDatabaseRealtimeEvents(
  queryClient: QueryClient,
  projectId: string,
  events: string[],
  payload: Record<string, unknown> | null,
): void {
  if (!isDedicatedDatabaseRealtimeSignal(events)) return

  const messageHasNestedResourceEvent = events.some((event) => {
    const parsed = parseDedicatedDatabaseEvent(event)
    return parsed?.resource != null
  })

  for (const event of events) {
    const policyEvent = parsePolicyEvent(event)
    if (policyEvent) {
      mergePolicyPayloadIntoCache(
        queryClient,
        projectId,
        policyEvent.policyId,
        payload ?? { $id: policyEvent.policyId },
        policyEvent.action,
      )
      continue
    }

    const parsed = parseDedicatedDatabaseEvent(event)
    if (!parsed) continue

    const { engine, databaseId, resource, resourceId, action } = parsed

    if (!resource) {
      if (
        payload &&
        typeof payload === 'object' &&
        isDatabaseLifecyclePayload(payload, databaseId, {
          messageHasNestedResourceEvent,
        })
      ) {
        mergeDedicatedDatabasePayloadIntoCache(
          queryClient,
          projectId,
          databaseId,
          payload,
          engine,
        )
      }
      if (action === 'delete') {
        invalidateQueryKey(queryClient, [
          'dedicated-databases',
          'project',
          projectId,
        ])
        invalidateQueryKey(queryClient, ['databases', 'project', projectId])
      } else {
        invalidateReplicaCaches(queryClient, projectId, databaseId)
        invalidateQueryKey(queryClient, [
          'dedicated-databases',
          'project',
          projectId,
        ])
        invalidateQueryKey(queryClient, [
          'database',
          'project',
          projectId,
          databaseId,
        ])
        invalidateQueryKey(queryClient, [
          'dedicated-database',
          'project',
          projectId,
          databaseId,
        ])
        invalidateNativeDatabaseDetailCaches(queryClient, projectId, databaseId)
      }
      continue
    }

    if (resource === 'backups' && resourceId) {
      mergeBackupPayloadIntoCache(
        queryClient,
        projectId,
        databaseId,
        resourceId,
        payload ?? { $id: resourceId },
        engine,
        action,
      )
      continue
    }

    if (resource === 'restorations' && resourceId) {
      mergeRestorationPayloadIntoCache(
        queryClient,
        projectId,
        databaseId,
        resourceId,
        payload ?? { $id: resourceId },
        action,
      )
      continue
    }

    if (resource === 'extensions') {
      invalidateExtensionCaches(queryClient, projectId, databaseId)
      continue
    }

    if (resource === 'branches') {
      invalidateQueryKey(queryClient, ['databases', 'project', projectId])
    }
  }
}
