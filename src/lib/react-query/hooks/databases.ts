/**
 * React Query hooks for Databases
 *
 * Handles databases, tables, rows, columns, and indexes.
 */

import {
  useQuery,
  useMutation,
  useQueryClient,
  queryOptions,
  keepPreviousData,
  type QueryClient,
} from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query, ID, DocumentsDBIndexType, TablesDBIndexType, VectorsDBIndexType, OrderBy, RelationshipType, RelationMutate } from '@appwrite.io/console'
import { DatabaseType, coerceDatabaseType } from '@/lib/databases/database-type'
import type { Models } from '@appwrite.io/console'
import type { Database, Collection } from '@/lib/utils/mock-data'
import { sdk } from '@/lib/appwrite/sdk'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { getDedicatedDatabaseIdError, resolveDedicatedDatabaseId } from '@/lib/dedicated-database-id'
import { SERVERLESS_DATABASE_SPEC_ID, isServerlessDatabaseSpecId } from '@/lib/database-specs'
import type { NativeDatabaseEngine } from '@/lib/databases/native-database-engines'
import { dedicatedEngineService } from '@/lib/databases/dedicated-engine'
import { requireOperationalDatabase } from '@/lib/databases/dedicated-database-write-lock'
import {
  DEDICATED_DATABASE_STATUS_POLL_INTERVAL_MS,
  shouldPollDedicatedDatabaseStatus,
} from '@/lib/databases/dedicated-database-status'
import { buildPostgresListSchemasSql } from '@/lib/postgres-sql'
import {
  normalizePostgresExecutionResult,
  wrapPostgresSqlForDisplay,
} from '@/lib/postgres-execution-values'
import {
  buildCollectionIndexableAttributes,
  getCollectionAttributeKey,
} from '@/lib/databases/collection-indexable-attributes'
import {
  databaseRouteKindFromApiType,
  isProductDatabaseRouteKindEnabled,
  isProductDatabaseTypeEnabled,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import {
  getDefaultEnabledSpecId,
  mapDedicatedDatabaseSpecifications,
} from '@/lib/database-specs'
import {
  groupEditsIntoUpdateOperations,
  serializeRowDataForApi,
  type PendingRowCellEdit,
} from '@/lib/database-row-inline-edits'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'
import {
  DEFAULT_STALE_TIME,
  DEFAULT_PAGE_SIZE,
  ROWS_DEFAULT_PAGE_SIZE,
  COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
} from './constants'

const MERGED_DATABASE_LIST_LIMIT = 500

/**
 * DocumentsDB/VectorsDB endpoints only exist on deployments with the matching
 * profile flag (cloud dedicated DBs). Skipping the calls keeps self-hosted
 * consoles from spamming 404s.
 */
function listProductDatabasesIfEnabled(
  projectSdk: ReturnType<typeof sdk.forProject>,
  backend: DatabaseType,
  queries: string[],
): Promise<Models.DatabaseList> {
  const features = getActiveProfileFeatures()
  if (backend === DatabaseType.Documentsdb) {
    if (!features.dedicatedDbsDocumentsDB) {
      return Promise.resolve({ total: 0, databases: [] })
    }
    return projectSdk.documentsDB.list({ queries })
  }
  if (backend === DatabaseType.Vectorsdb) {
    if (!features.dedicatedDbsVectorsDB) {
      return Promise.resolve({ total: 0, databases: [] })
    }
    return projectSdk.vectorsDB.list({ queries })
  }
  return projectSdk.tablesDB.list({ queries })
}

/**
 * Module-level dedup for `getDatabaseModel`.
 *
 * Many fetch functions (`fetchProjectTables`, `fetchProjectTableRows`,
 * `fetchProjectTableColumns`, `fetchProjectTableIndexes`, `fetchProjectTable`,
 * `fetchProjectDatabase`, …) all need the database "type" before calling the
 * right SDK (tables / documents / vectors). When a single route loader uses
 * `Promise.all` to prefetch rows + columns + indexes + table + database, that
 * was firing a fresh `tablesDB.get({ databaseId })` per query – often 6–8
 * duplicate calls back-to-back (visible in the network tab).
 *
 * This dedup:
 *   1. Returns the same in-flight promise for concurrent callers (same key).
 *   2. Caches the resolved value briefly (matching `DEFAULT_STALE_TIME`) so
 *      near-sequential callers also hit the cache without re-fetching.
 *
 * Failures are not cached. Mutations that change a database should call
 * `invalidateDatabaseModel(projectId, databaseId)` so the next call refetches.
 */
const databaseModelInflight = new Map<
  string,
  Promise<Models.Database | null>
>()
const databaseModelCache = new Map<
  string,
  { value: Models.Database | null; expiresAt: number }
>()

function databaseModelCacheKey(projectId: string, databaseId: string): string {
  return `${projectId}:${databaseId}`
}

/** Clear the dedup cache for one database (call after delete/update). */
export function invalidateDatabaseModel(
  projectId: string,
  databaseId: string,
): void {
  const key = databaseModelCacheKey(projectId, databaseId)
  databaseModelCache.delete(key)
  databaseModelInflight.delete(key)
  databaseTypeCache.delete(key)
  databaseTypeInflight.delete(key)
}

const DEDICATED_DATABASE_READY_STATUSES = new Set(['ready', 'paused'])

/** Refetch product and dedicated database list queries after create/delete. */
export async function refetchProjectDatabaseLists(
  queryClient: QueryClient,
  projectId: string,
): Promise<void> {
  await Promise.all([
    queryClient.refetchQueries({
      queryKey: ['databases', 'project', projectId],
      type: 'all',
    }),
    queryClient.refetchQueries({
      queryKey: ['dedicated-databases', 'project', projectId],
      type: 'all',
    }),
  ])
}

/** Poll dedicated database status until ready or timeout. */
export async function waitForDedicatedDatabaseReady(
  projectId: string,
  databaseId: string,
  maxAttempts = 30,
): Promise<boolean> {
  let intervalMs = 500
  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    try {
      const { databases } = await fetchProjectDedicatedDatabases(projectId)
      const status = databases.find((db) => db.$id === databaseId)?.status
      if (status && DEDICATED_DATABASE_READY_STATUSES.has(status)) {
        return true
      }
      if (status === 'failed' || status === 'error') {
        return false
      }
    } catch {
      /* retry */
    }
    if (attempt < maxAttempts - 1) {
      await sleep(intervalMs)
      intervalMs = Math.min(Math.round(intervalMs * 1.25), 3000)
    }
  }
  return false
}

export type CreatedDatabaseWorkspaceKind =
  | { type: 'product'; backend: DatabaseType }
  | { type: 'native'; engine: NativeDatabaseEngine }

async function probeProductDatabaseTablesList(
  projectId: string,
  databaseId: string,
  backend: DatabaseType,
): Promise<boolean> {
  const projectSdk = sdk.forProject(projectId)
  const queries = [Query.limit(1)]
  try {
    if (backend === DatabaseType.Documentsdb) {
      await projectSdk.documentsDB.listCollections({ databaseId, queries })
      return true
    }
    if (backend === DatabaseType.Vectorsdb) {
      await projectSdk.vectorsDB.listCollections({ databaseId, queries })
      return true
    }
    await projectSdk.tablesDB.listTables({ databaseId, queries })
    return true
  } catch {
    return false
  }
}

async function probeNativeDatabaseSchemasList(
  projectId: string,
  databaseId: string,
  engine: NativeDatabaseEngine,
): Promise<boolean> {
  try {
    const projectSdk = sdk.forProject(projectId)
    if (engine === 'postgres') {
      const sql = buildPostgresListSchemasSql({ limit: 1, offset: 0 })
      const execution = await projectSdk.postgresql.createExecution({
        databaseId,
        sql: wrapPostgresSqlForDisplay(sql),
      })
      normalizePostgresExecutionResult(execution)
      return true
    }
    const execution = await projectSdk.mysql.createExecution({
      databaseId,
      sql: 'SHOW DATABASES',
    })
    normalizePostgresExecutionResult(execution)
    return true
  } catch {
    return false
  }
}

/** True when the created database workspace can load schemas or tables/collections. */
export async function canLoadCreatedDatabaseWorkspace(
  projectId: string,
  databaseId: string,
  kind: CreatedDatabaseWorkspaceKind,
): Promise<boolean> {
  if (!projectId || !databaseId) return false
  if (kind.type === 'native') {
    return probeNativeDatabaseSchemasList(projectId, databaseId, kind.engine)
  }
  return probeProductDatabaseTablesList(projectId, databaseId, kind.backend)
}

/** Poll until schemas (native) or tables/collections (product) can be loaded. */
export async function waitForCreatedDatabaseWorkspaceReady(
  projectId: string,
  databaseId: string,
  kind: CreatedDatabaseWorkspaceKind,
  maxAttempts = 40,
): Promise<boolean> {
  let intervalMs = 500
  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    if (await canLoadCreatedDatabaseWorkspace(projectId, databaseId, kind)) {
      return true
    }
    if (attempt < maxAttempts - 1) {
      await sleep(intervalMs)
      intervalMs = Math.min(Math.round(intervalMs * 1.25), 3000)
    }
  }
  return false
}

function seedDatabaseModelCache(
  projectId: string,
  databaseId: string,
  db: Models.Database,
  backend: DatabaseType,
): void {
  const normalized = normalizeProductDatabase(db, backend)
  const key = databaseModelCacheKey(projectId, databaseId)
  const expiresAt = Date.now() + DEFAULT_STALE_TIME
  databaseModelCache.set(key, { value: normalized, expiresAt })
  databaseTypeCache.set(key, { value: backend, expiresAt })
}

function readProductDatabaseSpecification(
  db: Models.Database,
): string | null {
  const value = (db as Models.Database & { specification?: unknown }).specification
  return typeof value === 'string' && value.trim() ? value.trim() : null
}

function readProductDatabaseLifecycleStatus(
  db: Models.Database,
): string | null {
  const value = db.status as unknown
  if (typeof value === 'string' && value.trim()) return value.trim()
  // SDK types `status` as DatabaseStatus (health object). Treat ready as the
  // lifecycle string the rest of the console expects for badges/cards.
  if (value && typeof value === 'object') {
    const status = value as Models.DatabaseStatus
    if (status.ready === true) return 'ready'
    if (status.health === 'unhealthy') return 'failed'
    if (status.health === 'degraded') return 'provisioning'
  }
  return null
}

function buildProjectDatabaseDetail(db: Models.Database) {
  const backupPolicies = db.policies ?? []
  const backupPolicyCount = backupPolicies.length
  const hasBackupPolicy = backupPolicyCount > 0
  const backupPolicy = backupPolicies[0] || null

  return {
    $id: db.$id,
    name: db.name || 'Unnamed Database',
    tables: 0,
    rows: 0,
    enabled: db.enabled !== false,
    createdAt: db.$createdAt || new Date().toISOString(),
    updatedAt: db.$updatedAt || db.$createdAt || new Date().toISOString(),
    hasBackupPolicy,
    backupPolicy,
    backupPolicyCount,
    databaseType: coerceDatabaseType(db.type),
    apiType: db.type,
    status: readProductDatabaseLifecycleStatus(db),
    replicas: typeof db.replicas === 'number' ? db.replicas : null,
    specification: readProductDatabaseSpecification(db),
  }
}

/**
 * Warm React Query and module caches after create so navigation does not re-probe APIs.
 */
export function seedCreatedDatabaseCaches(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  backend: DatabaseType,
  database: Models.Database,
): void {
  seedDatabaseModelCache(projectId, databaseId, database, backend)

  const normalized = normalizeProductDatabase(database, backend)
  const routeKind = databaseRouteKindFromApiType(backend)

  queryClient.setQueryData(
    productRouteKindQueryOptions(projectId, databaseId).queryKey,
    routeKind,
  )
  queryClient.setQueryData(
    databaseQueryOptions(projectId, databaseId).queryKey,
    buildProjectDatabaseDetail(normalized),
  )
  queryClient.setQueryData(
    tablesQueryOptions(
      projectId,
      databaseId,
      0,
      ROWS_DEFAULT_PAGE_SIZE,
      undefined,
      'asc',
      '$createdAt',
    ).queryKey,
    { tables: [], total: 0 },
  )
}

const databaseTypeInflight = new Map<string, Promise<DatabaseType>>()
const databaseTypeCache = new Map<
  string,
  { value: DatabaseType; expiresAt: number }
>()

function routeKindToDatabaseType(kind: DatabaseRouteKind): DatabaseType {
  if (kind === 'documentsdb') return DatabaseType.Documentsdb
  if (kind === 'vectorsdb') return DatabaseType.Vectorsdb
  return DatabaseType.Tablesdb
}

function readCachedDatabaseType(
  projectId: string,
  databaseId: string,
): DatabaseType | undefined {
  const key = databaseModelCacheKey(projectId, databaseId)
  const cached = databaseTypeCache.get(key)
  if (cached && cached.expiresAt > Date.now()) {
    return cached.value
  }
  return undefined
}

/**
 * Pin product SDK routing from the URL segment on typed database routes.
 * DocumentsDB and VectorsDB can share the same dedicated ID; probing without
 * a hint may resolve to the wrong product API.
 */
export function seedDatabaseProductRouteKind(
  projectId: string,
  databaseId: string,
  dbKind: DatabaseRouteKind,
): void {
  const features = getActiveProfileFeatures()
  if (!isProductDatabaseRouteKindEnabled(dbKind, features)) return

  const backend = routeKindToDatabaseType(dbKind)
  const key = databaseModelCacheKey(projectId, databaseId)
  const expiresAt = Date.now() + DEFAULT_STALE_TIME
  databaseTypeCache.set(key, { value: backend, expiresAt })

  const cachedModel = databaseModelCache.get(key)
  if (cachedModel && cachedModel.value?.type !== backend) {
    databaseModelCache.delete(key)
    databaseModelInflight.delete(key)
  }
}

/** Resolve which product SDK owns a database (probe order: documents, vectors, tables). */
export async function resolveProjectDatabaseType(
  projectId: string,
  databaseId: string,
  routeKindHint?: DatabaseRouteKind,
): Promise<DatabaseType> {
  if (!projectId || !databaseId) return DatabaseType.Tablesdb

  if (routeKindHint) {
    return routeKindToDatabaseType(routeKindHint)
  }

  const key = databaseModelCacheKey(projectId, databaseId)
  const now = Date.now()

  const cached = databaseTypeCache.get(key)
  if (cached && cached.expiresAt > now) {
    return cached.value
  }

  const inFlight = databaseTypeInflight.get(key)
  if (inFlight) return inFlight

  const promise = (async () => {
    const routeKind = await resolveProductRouteKindForDatabase(
      projectId,
      databaseId,
    )
    return routeKind
      ? routeKindToDatabaseType(routeKind)
      : DatabaseType.Tablesdb
  })()

  databaseTypeInflight.set(key, promise)
  try {
    const value = await promise
    databaseTypeCache.set(key, {
      value,
      expiresAt: Date.now() + DEFAULT_STALE_TIME,
    })
    return value
  } finally {
    databaseTypeInflight.delete(key)
  }
}

/** Resolve a database from whichever product API owns it (Tables, Documents, or Vectors). */
export async function getDatabaseModel(
  projectId: string,
  databaseId: string,
  routeKindHint?: DatabaseRouteKind,
): Promise<Models.Database | null> {
  if (!projectId || !databaseId) return null

  const key = databaseModelCacheKey(projectId, databaseId)
  const now = Date.now()

  const cached = databaseModelCache.get(key)
  if (cached && cached.expiresAt > now) {
    const typeHint =
      routeKindHint != null
        ? routeKindToDatabaseType(routeKindHint)
        : readCachedDatabaseType(projectId, databaseId)
    if (!typeHint || cached.value?.type === typeHint) {
      return cached.value
    }
    databaseModelCache.delete(key)
  }

  const inFlight = databaseModelInflight.get(key)
  if (inFlight) return inFlight

  const projectSdk = sdk.forProject(projectId)
  const typeHint =
    routeKindHint != null
      ? routeKindToDatabaseType(routeKindHint)
      : readCachedDatabaseType(projectId, databaseId)

  const promise = (async () => {
    if (typeHint) {
      const db = await getProductDatabase(projectSdk, typeHint, databaseId)
      if (db?.$id) {
        return normalizeProductDatabase(db, typeHint)
      }
    }

    for (const { backend } of PRODUCT_ROUTE_KIND_PROBES) {
      if (typeHint && backend === typeHint) continue
      const db = await getProductDatabase(projectSdk, backend, databaseId)
      if (db?.$id) {
        return normalizeProductDatabase(db, backend)
      }
    }
    return null
  })()

  databaseModelInflight.set(key, promise)
  try {
    const value = await promise
    databaseModelCache.set(key, {
      value,
      expiresAt: Date.now() + DEFAULT_STALE_TIME,
    })
    if (value?.type) {
      databaseTypeCache.set(key, {
        value: coerceDatabaseType(value.type),
        expiresAt: Date.now() + DEFAULT_STALE_TIME,
      })
    }
    return value
  } finally {
    databaseModelInflight.delete(key)
  }
}

function databaseTypeRank(type: string | undefined): number {
  switch (type) {
    case DatabaseType.Documentsdb:
      return 0
    case DatabaseType.Vectorsdb:
      return 1
    case DatabaseType.Tablesdb:
      return 2
    default:
      return 3
  }
}

function normalizeProductDatabase(
  db: Models.Database,
  sourceType: DatabaseType | string,
): Models.Database {
  return {
    ...db,
    // The probing API is authoritative; `type` on the payload is unreliable.
    type: String(sourceType),
  }
}

function mergeProjectDatabasesById(
  sources: Array<{ databases?: Models.Database[]; defaultType: DatabaseType }>,
): Models.Database[] {
  const byId = new Map<string, Models.Database>()
  for (const { databases, defaultType } of sources) {
    for (const db of databases ?? []) {
      if (!db?.$id) continue
      const normalized = normalizeProductDatabase(db, defaultType)
      const existing = byId.get(db.$id)
      if (
        !existing ||
        databaseTypeRank(normalized.type) < databaseTypeRank(existing.type)
      ) {
        byId.set(db.$id, normalized)
      }
    }
  }
  return [...byId.values()]
}

function flattenDocumentForTableRow(
  doc: Record<string, unknown>,
): Record<string, unknown> {
  const base: Record<string, unknown> = {
    $id: doc.$id,
    $sequence: doc.$sequence,
    $createdAt: doc.$createdAt,
    $updatedAt: doc.$updatedAt,
    $permissions: doc.$permissions,
  }
  const nested = doc.data
  if (nested && typeof nested === 'object' && !Array.isArray(nested)) {
    return { ...base, ...(nested as Record<string, unknown>) }
  }
  return { ...doc }
}

function mapCollectionAttributesToColumnLike(
  attributes: unknown[] | undefined,
): unknown[] {
  if (!Array.isArray(attributes)) return []
  return attributes
    .map((raw) => {
      const a = raw as Record<string, unknown>
      const key = getCollectionAttributeKey(a)
      if (!key) return null
      const type = String(a.type ?? 'string')
      return {
        ...a,
        key,
        type,
        status: (a.status as string) || 'available',
      }
    })
    .filter((col) => col !== null)
}

function normalizeIndexesForTableUi<T extends Record<string, unknown>>(
  indexes: T[] | undefined,
): T[] {
  if (!indexes?.length) return []
  return indexes.map((idx) => {
    const cols = idx.columns ?? idx.attributes
    return {
      ...idx,
      columns: Array.isArray(cols) ? cols : [],
    } as T
  })
}

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch databases for a project
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated databases with total count
 */
export async function fetchProjectDatabases(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
) {
  if (!projectId) {
    return { databases: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const searchArg = search?.trim() || undefined
  const mergeQueries = [
    ...(filterQueries ?? []),
    ...(searchArg ? [Query.search('name', searchArg)] : []),
    Query.orderDesc('$createdAt'),
    Query.limit(MERGED_DATABASE_LIST_LIMIT),
  ]

  const settled = await Promise.allSettled([
    listProductDatabasesIfEnabled(
      projectSdk,
      DatabaseType.Documentsdb,
      mergeQueries,
    ),
    listProductDatabasesIfEnabled(
      projectSdk,
      DatabaseType.Vectorsdb,
      mergeQueries,
    ),
    projectSdk.tablesDB.list({ queries: mergeQueries }),
  ])

  const merged = mergeProjectDatabasesById([
    {
      databases:
        settled[0].status === 'fulfilled' ? settled[0].value.databases : [],
      defaultType: DatabaseType.Documentsdb,
    },
    {
      databases:
        settled[1].status === 'fulfilled' ? settled[1].value.databases : [],
      defaultType: DatabaseType.Vectorsdb,
    },
    {
      databases:
        settled[2].status === 'fulfilled' ? settled[2].value.databases : [],
      defaultType: DatabaseType.Tablesdb,
    },
  ])

  const sorted = [...merged].sort(
    (a, b) =>
      new Date(b.$createdAt).getTime() - new Date(a.$createdAt).getTime(),
  )

  const total = sorted.length
  const slice = sorted.slice(page * limit, page * limit + limit)

  return {
    databases: slice,
    total,
  }
}

/**
 * Fetch paginated databases for a single product API (TablesDB, DocumentsDB, or VectorsDB).
 */
export async function fetchProjectProductDatabases(
  projectId: string,
  backend: DatabaseType,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
) {
  if (!projectId) {
    return { databases: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const searchArg = search?.trim() || undefined
  const queries = [
    ...(filterQueries ?? []),
    ...(searchArg ? [Query.search('name', searchArg)] : []),
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await listProductDatabasesIfEnabled(
    projectSdk,
    backend,
    queries,
  )

  const databases = (response.databases ?? []).map((db) =>
    normalizeProductDatabase(db, backend),
  )

  return {
    databases,
    total: response.total ?? databases.length,
  }
}

/**
 * Fetch paginated databases via the Console unified list API
 * (`projectSdk.console.listDatabases`), which returns every database across
 * product APIs in a single call.
 */
export async function fetchProjectConsoleDatabases(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
) {
  if (!projectId) {
    return { databases: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const searchArg = search?.trim() || undefined
  const queries = [
    ...(filterQueries ?? []),
    ...(searchArg ? [Query.contains('name', searchArg)] : []),
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  // Same `/console/databases` list path used by All Databases.
  const response = await projectSdk.console.listDatabases({ queries })

  const databases = (response.databases ?? []).map((db) =>
    normalizeProductDatabase(db, db.type ?? DatabaseType.Tablesdb),
  )

  return {
    databases,
    total: response.total ?? databases.length,
  }
}

/** Fetch up to 7 databases by ID in three list calls (Documents, Vectors, Tables). */
export async function fetchProjectDatabasesByIds(
  projectId: string,
  databaseIds: string[],
): Promise<{ databases: Models.Database[] }> {
  if (!projectId || databaseIds.length === 0) {
    return { databases: [] }
  }

  const validIds = [
    ...new Set(
      databaseIds.filter((id) => typeof id === 'string' && id.trim()),
    ),
  ].slice(0, OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT)
  if (validIds.length === 0) {
    return { databases: [] }
  }

  const idQuery =
    validIds.length === 1
      ? Query.equal('$id', validIds[0])
      : Query.or(validIds.map((id) => Query.equal('$id', id)))

  const projectSdk = sdk.forProject(projectId)
  const settled = await Promise.allSettled([
    listProductDatabasesIfEnabled(projectSdk, DatabaseType.Documentsdb, [
      idQuery,
      Query.limit(validIds.length),
    ]),
    listProductDatabasesIfEnabled(projectSdk, DatabaseType.Vectorsdb, [
      idQuery,
      Query.limit(validIds.length),
    ]),
    projectSdk.tablesDB.list({
      queries: [idQuery, Query.limit(validIds.length)],
    }),
  ])

  const databases = mergeProjectDatabasesById([
    {
      databases:
        settled[0].status === 'fulfilled' ? settled[0].value.databases : [],
      defaultType: DatabaseType.Documentsdb,
    },
    {
      databases:
        settled[1].status === 'fulfilled' ? settled[1].value.databases : [],
      defaultType: DatabaseType.Vectorsdb,
    },
    {
      databases:
        settled[2].status === 'fulfilled' ? settled[2].value.databases : [],
      defaultType: DatabaseType.Tablesdb,
    },
  ])

  return { databases }
}

/**
 * Query function to fetch a single database by ID
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @returns Single database or null
 */
export async function fetchProjectDatabase(
  projectId: string,
  databaseId: string,
  routeKindHint?: DatabaseRouteKind,
) {
  if (!projectId || !databaseId) {
    return null
  }

  try {
    const db = await getDatabaseModel(projectId, databaseId, routeKindHint)

    if (!db) {
      return null
    }

    return buildProjectDatabaseDetail(db) as Database & {
      enabled: boolean
      createdAt: string
      updatedAt: string
      hasBackupPolicy: boolean
      backupPolicy: Record<string, unknown> | null
      backupPolicyCount: number
      databaseType: DatabaseType
    }
  } catch {
    return null
  }
}

/**
 * Options for creating Appwrite product databases (TablesDB, DocumentsDB, VectorsDB).
 * Dedicated tiers use the product API (`*.create` with `specification` and optional
 * `replicas`). Engine provisioning is only used when PITR is requested on create.
 */
export type CreateProjectDatabaseOptions = {
  specification?: string
  region?: string | null
  haReplicaCount?: number
  pitrEnabled?: boolean
}

function computeApiForDatabaseType(backend: DatabaseType): string {
  if (backend === DatabaseType.Documentsdb) return 'documentsdb'
  if (backend === DatabaseType.Vectorsdb) return 'vectorsdb'
  return 'tablesdb'
}

function dedicatedComputeEngineForProductBackend(
  backend: DatabaseType,
): 'mongodb' | 'postgres' | undefined {
  if (backend === DatabaseType.Documentsdb) return 'mongodb'
  if (backend === DatabaseType.Vectorsdb) return 'postgres'
  return undefined
}

function requiresDedicatedCompute(backend: DatabaseType): boolean {
  return (
    backend === DatabaseType.Documentsdb ||
    backend === DatabaseType.Vectorsdb
  )
}

async function resolveDedicatedSpecification(
  projectId: string,
  region?: string | null,
  explicit?: string,
  engine?: string,
): Promise<string> {
  if (explicit && explicit !== SERVERLESS_DATABASE_SPEC_ID) return explicit

  const projectSdk = sdk.forProject(
    projectId,
    region && region.trim() !== '' && region !== 'unknown'
      ? region.trim()
      : undefined,
  )
  const response =
    await dedicatedEngineService(projectSdk, engine).listSpecifications()
  const specs = mapDedicatedDatabaseSpecifications(response.specifications)
  const defaultId = getDefaultEnabledSpecId(specs)
  if (!defaultId) {
    throw new Error(
      'No dedicated database specifications are available for your plan.',
    )
  }
  return defaultId
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function getProductDatabase(
  projectSdk: ReturnType<typeof sdk.forProject>,
  backend: DatabaseType,
  databaseId: string,
): Promise<Models.Database | null> {
  const features = getActiveProfileFeatures()
  try {
    if (backend === DatabaseType.Documentsdb) {
      if (!features.dedicatedDbsDocumentsDB) return null
      return await projectSdk.documentsDB.get({ databaseId })
    }
    if (backend === DatabaseType.Vectorsdb) {
      if (!features.dedicatedDbsVectorsDB) return null
      return await projectSdk.vectorsDB.get({ databaseId })
    }
    return await projectSdk.tablesDB.get({ databaseId })
  } catch {
    return null
  }
}

const PRODUCT_ROUTE_KIND_PROBES: Array<{
  kind: DatabaseRouteKind
  backend: DatabaseType
}> = [
  { kind: 'documentsdb', backend: DatabaseType.Documentsdb },
  { kind: 'vectorsdb', backend: DatabaseType.Vectorsdb },
  { kind: 'tablesdb', backend: DatabaseType.Tablesdb },
]

/**
 * Resolve which product route tree owns a database by probing each product API.
 * Prefer documentsdb and vectorsdb over tablesdb when IDs collide.
 */
export async function resolveProductRouteKindForDatabase(
  projectId: string,
  databaseId: string,
  hint?: DatabaseRouteKind,
): Promise<DatabaseRouteKind | null> {
  if (!projectId || !databaseId) return null

  const features = getActiveProfileFeatures()
  const cachedType = readCachedDatabaseType(projectId, databaseId)
  if (cachedType && isProductDatabaseTypeEnabled(cachedType, features)) {
    return databaseRouteKindFromApiType(cachedType)
  }

  const projectSdk = sdk.forProject(projectId)

  if (hint) {
    const backend = routeKindToDatabaseType(hint)
    const db = await getProductDatabase(projectSdk, backend, databaseId)
    if (db?.$id) return hint
  }

  for (const { kind, backend } of PRODUCT_ROUTE_KIND_PROBES) {
    if (hint && kind === hint) continue
    const db = await getProductDatabase(projectSdk, backend, databaseId)
    if (db?.$id) return kind
  }
  return null
}

export function productRouteKindQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['database', 'product-route-kind', projectId, databaseId],
    queryFn: async () => {
      const features = getActiveProfileFeatures()
      const cachedType = readCachedDatabaseType(projectId!, databaseId!)
      if (cachedType && isProductDatabaseTypeEnabled(cachedType, features)) {
        return databaseRouteKindFromApiType(cachedType)
      }
      return resolveProductRouteKindForDatabase(projectId!, databaseId!)
    },
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

function getProductDatabaseIdError(id: string): string | null {
  const trimmed = id.trim()
  if (!trimmed) return null
  if (trimmed.length > 36) {
    return 'Database ID must be 36 characters or less.'
  }
  if (!/^[a-zA-Z0-9_]/.test(trimmed)) {
    return 'Database ID cannot start with a special character.'
  }
  if (!/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/.test(trimmed)) {
    return 'Database ID must be alphanumeric, underscore, hyphen, or period.'
  }
  return null
}

function resolveProductDatabaseId(customId?: string | null): string {
  const trimmed = customId?.trim()
  return trimmed && trimmed !== '' ? trimmed : ID.unique()
}

async function waitForProductDatabaseAfterExistsConflict(
  projectSdk: ReturnType<typeof sdk.forProject>,
  backend: DatabaseType,
  databaseId: string,
  maxAttempts = 8,
): Promise<Models.Database | null> {
  let intervalMs = 400
  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    const existing = await getProductDatabase(projectSdk, backend, databaseId)
    if (existing) return existing
    if (attempt < maxAttempts - 1) {
      await sleep(intervalMs)
      intervalMs = Math.min(Math.round(intervalMs * 1.5), 2000)
    }
  }
  return null
}

function isDatabaseAlreadyExistsError(error: unknown): boolean {
  const message =
    error instanceof Error
      ? error.message
      : typeof error === 'string'
        ? error
        : ''
  return message.toLowerCase().includes('already exists')
}

async function createProductDatabase(
  projectSdk: ReturnType<typeof sdk.forProject>,
  backend: DatabaseType,
  params: {
    databaseId: string
    name: string
    specification?: string
    replicas?: number
  },
) {
  const payload = {
    databaseId: params.databaseId,
    name: params.name,
    ...(params.specification ? { specification: params.specification } : {}),
    ...(params.replicas != null && params.replicas > 0
      ? { replicas: params.replicas }
      : {}),
  }

  if (backend === DatabaseType.Documentsdb) {
    return await projectSdk.documentsDB.create(payload)
  }
  if (backend === DatabaseType.Vectorsdb) {
    return await projectSdk.vectorsDB.create(payload)
  }
  return await projectSdk.tablesDB.create(payload)
}

async function createProductDatabaseWithExistsRecovery(
  projectSdk: ReturnType<typeof sdk.forProject>,
  backend: DatabaseType,
  params: {
    databaseId: string
    name: string
    specification?: string
    replicas?: number
  },
): Promise<Models.Database> {
  try {
    return await createProductDatabase(projectSdk, backend, params)
  } catch (error) {
    if (!isDatabaseAlreadyExistsError(error)) {
      throw error
    }
    const existing = await getProductDatabase(
      projectSdk,
      backend,
      params.databaseId,
    )
    if (existing) return existing
    throw error
  }
}

/**
 * Provision dedicated compute owned by a product API (tablesdb / documentsdb /
 * vectorsdb). Engine is selected via the per-engine SDK service; `api` marks
 * which product API exposes the database under the same ID.
 */
async function provisionDedicatedCompute(
  projectSdk: ReturnType<typeof sdk.forProject>,
  params: {
    databaseId: string
    name: string
    specification: string
    backend: DatabaseType
    haReplicaCount: number
    pitrEnabled: boolean
  },
): Promise<Models.DedicatedDatabase> {
  const { databaseId, name, specification, backend, haReplicaCount, pitrEnabled } =
    params

  const engine = dedicatedComputeEngineForProductBackend(backend)
  const engineService = dedicatedEngineService(projectSdk, engine ?? undefined)

  try {
    return await engineService.create({
      databaseId,
      name,
      specification,
      replicas: haReplicaCount,
      pitr: pitrEnabled,
      api: computeApiForDatabaseType(backend),
    })
  } catch (error) {
    if (!isDatabaseAlreadyExistsError(error)) {
      throw error
    }
    return await engineService.get({ databaseId })
  }
}

/**
 * Create a new database in a project.
 * Product databases are created in one call via the product SDK (`tablesDB`,
 * `documentsDB`, or `vectorsDB`) with an optional `specification` and `replicas`.
 * Engine provisioning is only used when PITR is requested on create.
 *
 * @param projectId - The project ID
 * @param data - { databaseId?: string; name: string }
 * @returns The created database (Models.Database)
 */
export async function createProjectDatabase(
  projectId: string,
  data: { databaseId?: string | null; name: string },
  backend: DatabaseType = DatabaseType.Tablesdb,
  options?: CreateProjectDatabaseOptions,
) {
  if (!projectId) {
    throw new Error('Project ID is required')
  }

  const region =
    options?.region &&
    options.region.trim() !== '' &&
    options.region !== 'unknown'
      ? options.region.trim()
      : undefined
  const projectSdk = sdk.forProject(projectId, region)

  const useDedicated =
    requiresDedicatedCompute(backend) ||
    (options?.specification != null &&
      options.specification !== SERVERLESS_DATABASE_SPEC_ID)

  const productDatabaseId = resolveProductDatabaseId(data.databaseId)

  if (data.databaseId?.trim()) {
    const idError = getProductDatabaseIdError(data.databaseId)
    if (idError) {
      throw new Error(idError)
    }
  }

  const name = data.name.trim()
  const haReplicaCount = Math.max(0, options?.haReplicaCount ?? 0)
  const pitrEnabled = options?.pitrEnabled === true
  const needsEngineProvisioning = pitrEnabled

  if (useDedicated) {
    const specification = await resolveDedicatedSpecification(
      projectId,
      region,
      options?.specification,
      dedicatedComputeEngineForProductBackend(backend),
    )

    let created: Models.Database

    if (needsEngineProvisioning) {
      await provisionDedicatedCompute(projectSdk, {
        databaseId: productDatabaseId,
        name,
        specification,
        backend,
        haReplicaCount,
        pitrEnabled,
      })

      const polled = await waitForProductDatabaseAfterExistsConflict(
        projectSdk,
        backend,
        productDatabaseId,
        20,
      )
      if (!polled) {
        throw new Error('Failed to create database')
      }
      created = polled
    } else {
      created = await createProductDatabaseWithExistsRecovery(
        projectSdk,
        backend,
        {
          databaseId: productDatabaseId,
          name,
          specification,
          ...(haReplicaCount > 0 ? { replicas: haReplicaCount } : {}),
        },
      )
    }

    seedDatabaseModelCache(projectId, productDatabaseId, created, backend)
    return normalizeProductDatabase(created, backend)
  }

  const created = await createProductDatabaseWithExistsRecovery(
    projectSdk,
    backend,
    {
      databaseId: productDatabaseId,
      name,
    },
  )
  seedDatabaseModelCache(projectId, productDatabaseId, created, backend)
  return normalizeProductDatabase(created, backend)
}

/**
 * Update database metadata (name, enabled) on the correct product SDK.
 */
export async function updateProjectDatabase(
  projectId: string,
  databaseId: string,
  data: { name: string; enabled?: boolean },
) {
  if (!projectId || !databaseId) {
    throw new Error('Project ID and Database ID are required')
  }
  const projectSdk = sdk.forProject(projectId)
  const kind = await resolveProjectDatabaseType(projectId, databaseId)
  const payload = {
    databaseId,
    name: data.name.trim(),
    ...(data.enabled !== undefined ? { enabled: data.enabled } : {}),
  }

  if (kind === DatabaseType.Documentsdb) {
    return await projectSdk.documentsDB.update(payload)
  }
  if (kind === DatabaseType.Vectorsdb) {
    return await projectSdk.vectorsDB.update(payload)
  }
  return await projectSdk.tablesDB.update(payload)
}

/**
 * Delete a database on the correct product SDK.
 */
export async function deleteProjectDatabase(
  projectId: string,
  databaseId: string,
) {
  if (!projectId || !databaseId) {
    throw new Error('Project ID and Database ID are required')
  }
  const projectSdk = sdk.forProject(projectId)
  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  if (kind === DatabaseType.Documentsdb) {
    return await projectSdk.documentsDB.delete({ databaseId })
  }
  if (kind === DatabaseType.Vectorsdb) {
    return await projectSdk.vectorsDB.delete({ databaseId })
  }
  return await projectSdk.tablesDB.delete({ databaseId })
}

export type { NativeDatabaseEngine } from '@/lib/databases/native-database-engines'

/**
 * Create a native Postgres or MySQL database via the engine-specific SDK service.
 */
export async function createNativeDatabase(
  projectId: string,
  data: {
    databaseId?: string | null
    name: string
    engine: NativeDatabaseEngine
    specification: string
    region?: string | null
    haReplicaCount?: number
    pitrEnabled?: boolean
  },
) {
  if (!projectId) {
    throw new Error('Project ID is required')
  }
  if (!data.specification.trim()) {
    throw new Error('Database specification is required')
  }

  const region =
    data.region && data.region.trim() !== '' && data.region !== 'unknown'
      ? data.region.trim()
      : undefined
  const projectSdk = sdk.forProject(projectId, region)

  if (data.databaseId?.trim()) {
    const idError = getDedicatedDatabaseIdError(data.databaseId)
    if (idError) {
      throw new Error(idError)
    }
  }

  const databaseId = resolveDedicatedDatabaseId(data.databaseId)

  const haReplicaCount = Math.max(0, data.haReplicaCount ?? 0)
  const pitrEnabled = data.pitrEnabled === true

  // Omit `api` for native (raw) databases; the API sets api from the engine.
  return await dedicatedEngineService(projectSdk, data.engine).create({
    databaseId,
    name: data.name.trim(),
    specification: data.specification.trim(),
    replicas: haReplicaCount,
    pitr: pitrEnabled,
  })
}

/** True when any feature needing the shared compute-tier specs endpoint is on. */
function isDatabaseSpecificationsSupported(): boolean {
  const features = getActiveProfileFeatures()
  return (
    features.dedicatedDbsSupport ||
    features.nativeDbsPostgres ||
    features.nativeDbsMySQL ||
    features.nativeDbsMongo
  )
}

export async function fetchDatabaseSpecifications(projectId: string) {
  if (!projectId || !isDatabaseSpecificationsSupported()) {
    return { specifications: [], total: 0, pricing: null }
  }

  // Compute tiers are shared across engines; postgres is the representative set.
  const response = await dedicatedEngineService(
    sdk.forProject(projectId),
    'postgres',
  ).listSpecifications()

  return {
    specifications: response.specifications ?? [],
    total: response.total ?? response.specifications?.length ?? 0,
    pricing: response.pricing ?? null,
  }
}

export function databaseSpecificationsQueryOptions(
  projectId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['database-specifications', 'project', projectId],
    queryFn: () => fetchDatabaseSpecifications(projectId!),
    enabled: !!projectId && isDatabaseSpecificationsSupported(),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useDatabaseSpecifications(
  projectId: string | null | undefined,
) {
  return useQuery(databaseSpecificationsQueryOptions(projectId))
}

/** True when at least one native DB engine (PostgreSQL/MySQL/MongoDB) is available. */
function isNativeDatabasesSupported(): boolean {
  const features = getActiveProfileFeatures()
  return (
    features.nativeDbsPostgres ||
    features.nativeDbsMySQL ||
    features.nativeDbsMongo
  )
}

/**
 * True when dedicated engine list/get should run. Product dedicated compute
 * (TablesDB / DocumentsDB / VectorsDB) uses the same engine endpoints even when
 * native DB UI flags are off.
 */
function isDedicatedEngineAccessSupported(): boolean {
  const features = getActiveProfileFeatures()
  return (
    isNativeDatabasesSupported() ||
    features.dedicatedDbsSupport ||
    features.dedicatedDbsDocumentsDB ||
    features.dedicatedDbsVectorsDB
  )
}

/** Engines to list/probe for native + product-owned dedicated compute. */
function dedicatedEnginesForProfile(): Array<
  'postgresql' | 'mysql' | 'mongodb'
> {
  const features = getActiveProfileFeatures()
  const engines: Array<'postgresql' | 'mysql' | 'mongodb'> = []
  if (
    features.nativeDbsPostgres ||
    features.dedicatedDbsVectorsDB ||
    features.dedicatedDbsSupport
  ) {
    engines.push('postgresql')
  }
  if (features.nativeDbsMySQL || features.dedicatedDbsSupport) {
    engines.push('mysql')
  }
  if (
    features.nativeDbsMongo ||
    features.dedicatedDbsDocumentsDB ||
    features.dedicatedDbsSupport
  ) {
    engines.push('mongodb')
  }
  return engines
}

function allowedDedicatedEngineKeys(): Set<string> {
  const allowed = new Set<string>()
  for (const engine of dedicatedEnginesForProfile()) {
    if (engine === 'postgresql') {
      allowed.add('postgresql')
      allowed.add('postgres')
    } else if (engine === 'mysql') {
      allowed.add('mysql')
      allowed.add('mariadb')
    } else {
      allowed.add('mongodb')
      allowed.add('mongo')
    }
  }
  return allowed
}

export async function fetchProjectDedicatedDatabases(projectId: string) {
  if (!projectId) {
    return { databases: [] as Models.DedicatedDatabase[], total: 0 }
  }

  // List engine databases (native + product-owned dedicated compute) and merge.
  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(MERGED_DATABASE_LIST_LIMIT),
  ]
  const engineLists: Promise<Models.DedicatedDatabaseList>[] = []
  for (const engine of dedicatedEnginesForProfile()) {
    engineLists.push(
      dedicatedEngineService(projectSdk, engine).list({ queries }),
    )
  }
  if (engineLists.length === 0) {
    return { databases: [] as Models.DedicatedDatabase[], total: 0 }
  }
  const results = await Promise.allSettled(engineLists)

  const fulfilled = results.filter(
    (r): r is PromiseFulfilledResult<Models.DedicatedDatabaseList> =>
      r.status === 'fulfilled',
  )

  // A 404 means the project doesn't use that engine (expected). Any other
  // rejection (auth, 5xx, timeout) is a real failure we must not silently drop.
  const realErrors = results.filter(
    (r): r is PromiseRejectedResult =>
      r.status === 'rejected' && (r.reason as { code?: number })?.code !== 404,
  )
  for (const r of realErrors) {
    console.warn('[dedicated-databases] engine list failed:', r.reason)
  }
  // If nothing succeeded and a real error occurred, surface it instead of
  // returning a misleading empty list. (All-404 legitimately means "none".)
  if (fulfilled.length === 0 && realErrors.length > 0) {
    throw realErrors[0].reason
  }

  const databases = fulfilled
    .flatMap((r) => r.value.databases ?? [])
    .sort((a, b) => (a.$createdAt < b.$createdAt ? 1 : -1))
    .slice(0, MERGED_DATABASE_LIST_LIMIT)

  // Sum each engine's server-side count so `total` stays accurate even when the
  // merged list is capped at MERGED_DATABASE_LIST_LIMIT.
  const total = fulfilled.reduce(
    (sum, r) => sum + (r.value.total ?? r.value.databases?.length ?? 0),
    0,
  )

  return { databases, total }
}

function productBackendsFromEngineHints(
  engineHints: string[],
): DatabaseType[] {
  const backends: DatabaseType[] = []
  const seen = new Set<DatabaseType>()
  const add = (backend: DatabaseType) => {
    if (seen.has(backend)) return
    seen.add(backend)
    backends.push(backend)
  }
  for (const hint of engineHints) {
    const key = hint.trim().toLowerCase()
    if (key === 'mongodb' || key === 'mongo') {
      add(DatabaseType.Documentsdb)
      continue
    }
    if (key === 'mysql' || key === 'mariadb') {
      add(DatabaseType.Tablesdb)
      continue
    }
    if (key === 'postgresql' || key === 'postgres') {
      // VectorsDB is Postgres-backed; TablesDB dedicated may also be.
      add(DatabaseType.Vectorsdb)
      add(DatabaseType.Tablesdb)
    }
  }
  return backends
}

function dedicatedCardSourceFromProductDatabase(
  db: Models.Database,
  backend: DatabaseType,
): Models.DedicatedDatabase | null {
  const specification = readProductDatabaseSpecification(db)
  if (!specification || isServerlessDatabaseSpecId(specification)) {
    return null
  }

  const engine =
    dedicatedComputeEngineForProductBackend(backend) === 'mongodb'
      ? 'mongodb'
      : dedicatedComputeEngineForProductBackend(backend) === 'postgres'
        ? 'postgresql'
        : 'mysql'

  // Product get/list can carry the compute slug without a full engine document.
  // Card UI only needs identity + tier fields; cast keeps Map typing simple.
  return {
    $id: db.$id,
    name: db.name,
    api: computeApiForDatabaseType(backend),
    engine,
    specification,
    status: readProductDatabaseLifecycleStatus(db) ?? 'ready',
    replicas: typeof db.replicas === 'number' ? db.replicas : 0,
    cpu: 0,
    memory: 0,
  } as Models.DedicatedDatabase
}

/**
 * Resolve a dedicated database by ID. Product-owned dedicated compute often
 * shares the product database ID but may be omitted from engine `list`
 * responses; `get` / id-filtered `list` still return the row on the right engine.
 * When the engine row is missing, fall back to the product get payload's
 * (untyped) `specification` field so list cards can show the compute tier.
 */
export async function fetchDedicatedDatabaseById(
  projectId: string,
  databaseId: string,
  engineHints: string[] = ['postgresql', 'mysql', 'mongodb'],
): Promise<Models.DedicatedDatabase | null> {
  if (!projectId || !databaseId) return null

  const projectSdk = sdk.forProject(projectId)
  const allowed = allowedDedicatedEngineKeys()

  const seen = new Set<string>()
  for (const hint of engineHints) {
    const key = hint.trim().toLowerCase()
    if (!key || seen.has(key) || !allowed.has(key)) continue
    seen.add(key)
    const engine = dedicatedEngineService(projectSdk, key)
    try {
      const database = await engine.get({ databaseId })
      if (database?.$id) return database
    } catch {
      /* try list filter, then next engine */
    }
    try {
      const listed = await engine.list({
        queries: [Query.equal('$id', databaseId), Query.limit(1)],
      })
      const database = listed.databases?.[0]
      if (database?.$id) return database
    } catch {
      /* try next engine */
    }
  }

  for (const backend of productBackendsFromEngineHints(engineHints)) {
    const product = await getProductDatabase(projectSdk, backend, databaseId)
    if (!product) continue
    const fromProduct = dedicatedCardSourceFromProductDatabase(product, backend)
    if (fromProduct) return fromProduct
  }

  return null
}

export function dedicatedDatabaseByIdQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  engineHints: string[] = ['postgresql', 'mysql', 'mongodb'],
) {
  return queryOptions({
    queryKey: [
      'dedicated-database',
      'project',
      projectId,
      databaseId,
      engineHints.join(','),
    ],
    queryFn: () =>
      fetchDedicatedDatabaseById(projectId!, databaseId!, engineHints),
    enabled: !!projectId && !!databaseId && isDedicatedEngineAccessSupported(),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function dedicatedDatabasesQueryOptions(
  projectId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['dedicated-databases', 'project', projectId],
    queryFn: () => fetchProjectDedicatedDatabases(projectId!),
    enabled: !!projectId && isDedicatedEngineAccessSupported(),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    refetchInterval: (query) => {
      const databases = query.state.data?.databases ?? []
      return databases.some((db) =>
        shouldPollDedicatedDatabaseStatus(db.status),
      )
        ? DEDICATED_DATABASE_STATUS_POLL_INTERVAL_MS
        : false
    },
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectDedicatedDatabases(
  projectId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    dedicatedDatabasesQueryOptions(projectId),
  )

  return {
    databases: data?.databases ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Create a new table (collection) in a database
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param data - The table data (tableId and name)
 * @returns Created table
 */
export type CreateCollectionAttributeInput = {
  key: string
  type: string
  size?: number
  required?: boolean
  default?: unknown
  array?: boolean
}

export async function createProjectTable(
  projectId: string,
  databaseId: string,
  data: {
    tableId?: string | null
    name: string
    dimension?: number
    attributes?: CreateCollectionAttributeInput[]
  },
) {
  if (!projectId || !databaseId) {
    throw new Error('Project ID and Database ID are required')
  }
  const projectSdk = sdk.forProject(projectId)
  const tableId =
    data.tableId && data.tableId.trim() !== ''
      ? data.tableId.trim()
      : ID.unique()

  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  if (kind === DatabaseType.Vectorsdb) {
    const dimension =
      typeof data.dimension === 'number' && data.dimension > 0
        ? data.dimension
        : 768
    return await projectSdk.vectorsDB.createCollection({
      databaseId,
      collectionId: tableId,
      name: data.name.trim(),
      dimension,
    })
  }

  if (kind === DatabaseType.Documentsdb) {
    return await projectSdk.documentsDB.createCollection({
      databaseId,
      collectionId: tableId,
      name: data.name.trim(),
      ...(data.attributes?.length ? { attributes: data.attributes } : {}),
    })
  }

  return await projectSdk.tablesDB.createTable({
    databaseId,
    tableId,
    name: data.name.trim(),
  })
}

/** Column definition for createTable (key, type, required, and type-specific options) */
export type CreateTableColumnDef = Record<string, unknown>

/** Index definition for createTable (key, type, attributes, optional orders and lengths) */
export type CreateTableIndexDef = {
  key: string
  type: string
  attributes: string[]
  orders?: string[]
  lengths?: number[]
}

/**
 * Create a new table with optional column and index structure.
 * Used for "create table similar to" flow.
 */
export async function createProjectTableWithStructure(
  projectId: string,
  databaseId: string,
  data: {
    tableId?: string | null
    name: string
    columns?: CreateTableColumnDef[]
    indexes?: object[]
  },
) {
  if (!projectId || !databaseId) {
    throw new Error('Project ID and Database ID are required')
  }
  const projectSdk = sdk.forProject(projectId)
  const tableId =
    data.tableId && data.tableId.trim() !== ''
      ? data.tableId.trim()
      : ID.unique()

  return await projectSdk.tablesDB.createTable({
    databaseId,
    tableId,
    name: data.name.trim(),
    columns: data.columns?.length ? data.columns : undefined,
    indexes: data.indexes?.length ? data.indexes : undefined,
  })
}

/**
 * Fetch table columns and indexes via listColumns/listIndexes and map to createTable format.
 * Only includes columns and indexes with status 'available'. Used for "create similar" flow.
 */
export async function fetchTableStructureForCopy(
  projectId: string,
  databaseId: string,
  tableId: string,
): Promise<{
  columns: CreateTableColumnDef[]
  indexes: CreateTableIndexDef[]
}> {
  if (!projectId || !databaseId || !tableId) {
    return { columns: [], indexes: [] }
  }
  const projectSdk = sdk.forProject(projectId)

  let list: Models.ColumnList
  try {
    list = await projectSdk.tablesDB.listColumns({
      databaseId,
      tableId,
      total: true,
    })
  } catch {
    return { columns: [], indexes: [] }
  }

  const columns = list.columns ?? []
  const columnDefs: CreateTableColumnDef[] = []
  for (const col of columns) {
    if ((col as { status?: string }).status !== 'available') continue
    const c = col as Record<string, unknown>
    const key = (c.key as string) || ''
    const type = (c.type as string) || 'string'
    const required = !!c.required
    const array = !!c.array
    const def: CreateTableColumnDef = {
      key,
      type,
      required,
      ...(array && { array: true }),
    }
    if (
      type === 'string' ||
      type === 'varchar' ||
      type === 'text' ||
      type === 'mediumtext' ||
      type === 'longtext'
    ) {
      if (typeof c.size === 'number') def.size = c.size
      else if (type === 'varchar') def.size = 255
      else if (type === 'string') def.size = 255
    }
    if (c.default !== undefined && c.default !== null) def.default = c.default
    if (type === 'integer' || type === 'bigint' || type === 'double') {
      if (typeof c.min !== 'undefined') def.min = c.min
      if (typeof c.max !== 'undefined') def.max = c.max
    }
    if (type === 'enum' && Array.isArray(c.elements)) def.elements = c.elements
    if (type === 'datetime' && typeof c.format === 'string')
      def.format = c.format
    if (type === 'relationship') {
      def.relatedTable = c.relatedTable
      def.relationType = c.relationType ?? c.relationshipType
      if (typeof c.twoWay === 'boolean') def.twoWay = c.twoWay
      if (typeof c.twoWayKey === 'string') def.twoWayKey = c.twoWayKey
      if (typeof c.onDelete === 'string') def.onDelete = c.onDelete
    }
    if (type === 'point' || type === 'linestring' || type === 'polygon') {
      if (typeof c.format === 'string') def.format = c.format
    }
    if (typeof c.encrypt === 'boolean') def.encrypt = c.encrypt
    columnDefs.push(def)
  }

  let indexList: Models.ColumnIndexList
  try {
    indexList = await projectSdk.tablesDB.listIndexes({
      databaseId,
      tableId,
      total: true,
    })
  } catch {
    return { columns: columnDefs, indexes: [] }
  }

  const indexDefs: CreateTableIndexDef[] = []
  const rawIndexes = (indexList.indexes ?? []) as Models.ColumnIndex[]
  for (const idx of rawIndexes) {
    if (idx.status !== 'available') continue
    const { key, type, columns: indexColumns, orders, lengths } = idx
    if (
      !key ||
      !type ||
      !Array.isArray(indexColumns) ||
      indexColumns.length === 0
    )
      continue
    const def: CreateTableIndexDef = {
      key,
      type,
      attributes: indexColumns,
    }
    if (Array.isArray(orders) && orders.length > 0) def.orders = orders
    if (Array.isArray(lengths) && lengths.length > 0) def.lengths = lengths
    indexDefs.push(def)
  }

  return { columns: columnDefs, indexes: indexDefs }
}

/**
 * Query function to fetch tables (collections) for a database
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated tables with total count
 */
/** Attribute to sort tables by in list APIs */
export type TablesSortBy = '$createdAt' | 'name' | '$updatedAt'

export async function fetchProjectTables(
  projectId: string,
  databaseId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  order: 'asc' | 'desc' = 'asc',
  sortBy: TablesSortBy = '$createdAt',
  routeKindHint?: DatabaseRouteKind,
) {
  if (!projectId || !databaseId) {
    return { tables: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    order === 'asc' ? Query.orderAsc(sortBy) : Query.orderDesc(sortBy),
    Query.limit(limit),
    Query.offset(page * limit),
  ]
  const searchArg = search?.trim() || undefined

  const kind = await resolveProjectDatabaseType(
    projectId,
    databaseId,
    routeKindHint,
  )

  if (kind === DatabaseType.Documentsdb) {
    try {
      const response = await projectSdk.documentsDB.listCollections({
        databaseId,
        queries,
        search: searchArg,
      })
      return {
        tables: response.collections ?? [],
        total: response.total ?? 0,
      }
    } catch {
      return { tables: [], total: 0 }
    }
  }

  if (kind === DatabaseType.Vectorsdb) {
    try {
      const response = await projectSdk.vectorsDB.listCollections({
        databaseId,
        queries,
        search: searchArg,
      })
      return {
        tables: response.collections ?? [],
        total: response.total ?? 0,
      }
    } catch {
      return { tables: [], total: 0 }
    }
  }

  let response: Models.TableList
  try {
    response = await projectSdk.tablesDB.listTables({
      databaseId,
      queries,
      search: searchArg,
    })
  } catch {
    response = { tables: [], total: 0 }
  }

  return {
    tables: response.tables ?? [],
    total: response.total ?? 0,
  }
}

/** Documents sampled per collection when inferring visualizer attribute lists. */
const VISUALIZER_DOCUMENT_SAMPLE_SIZE = 25
/** Max concurrent document-sample fetches while building visualizer columns. */
const VISUALIZER_SAMPLE_CONCURRENCY = 10

async function mapWithConcurrency<T, R>(
  items: T[],
  concurrency: number,
  mapper: (item: T, index: number) => Promise<R>,
): Promise<R[]> {
  if (items.length === 0) return []
  const results: R[] = new Array(items.length)
  let nextIndex = 0

  async function worker() {
    while (nextIndex < items.length) {
      const index = nextIndex++
      results[index] = await mapper(items[index]!, index)
    }
  }

  const workerCount = Math.min(Math.max(concurrency, 1), items.length)
  await Promise.all(Array.from({ length: workerCount }, () => worker()))
  return results
}

/**
 * Enrich Documents/Vectors collections with a `columns` list for the schema visualizer.
 * Merges declared attributes with keys discovered on a small document sample (schemaless fields).
 */
async function enrichCollectionsForVisualizer(
  projectId: string,
  databaseId: string,
  collections: Models.Collection[],
  kind: DatabaseType.Documentsdb | DatabaseType.Vectorsdb,
) {
  const projectSdk = sdk.forProject(projectId)
  const listDocuments =
    kind === DatabaseType.Documentsdb
      ? projectSdk.documentsDB.listDocuments.bind(projectSdk.documentsDB)
      : projectSdk.vectorsDB.listDocuments.bind(projectSdk.vectorsDB)

  return mapWithConcurrency(
    collections,
    VISUALIZER_SAMPLE_CONCURRENCY,
    async (collection) => {
      let sampleRows: Record<string, unknown>[] = []
      try {
        const docsResponse = await listDocuments({
          databaseId,
          collectionId: collection.$id,
          queries: [Query.limit(VISUALIZER_DOCUMENT_SAMPLE_SIZE)],
          total: false,
        })
        sampleRows = (
          (docsResponse.documents ?? []) as Record<string, unknown>[]
        ).map((doc) => flattenDocumentForTableRow(doc))
      } catch {
        sampleRows = []
      }

      const columns = buildCollectionIndexableAttributes(
        collection.attributes as unknown[] | undefined,
        sampleRows,
      )

      return {
        ...collection,
        columns,
        indexes: normalizeIndexesForTableUi(
          collection.indexes as Array<Record<string, unknown>> | undefined,
        ),
      }
    },
  )
}

/**
 * Query function to fetch all tables with full details (columns, indexes) for visualizer
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @returns All tables with columns and indexes
 */
export async function fetchAllProjectTablesForVisualizer(
  projectId: string,
  databaseId: string,
) {
  if (!projectId || !databaseId) {
    return { tables: [] }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(1000),
  ]

  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  if (kind === DatabaseType.Documentsdb) {
    try {
      const response = await projectSdk.documentsDB.listCollections({
        databaseId,
        queries,
      })
      const tables = await enrichCollectionsForVisualizer(
        projectId,
        databaseId,
        response.collections ?? [],
        DatabaseType.Documentsdb,
      )
      return { tables }
    } catch {
      return { tables: [] }
    }
  }

  if (kind === DatabaseType.Vectorsdb) {
    try {
      const response = await projectSdk.vectorsDB.listCollections({
        databaseId,
        queries,
      })
      const tables = await enrichCollectionsForVisualizer(
        projectId,
        databaseId,
        response.collections ?? [],
        DatabaseType.Vectorsdb,
      )
      return { tables }
    } catch {
      return { tables: [] }
    }
  }

  let response: Models.TableList
  try {
    response = await projectSdk.tablesDB.listTables({
      databaseId,
      queries,
    })
  } catch {
    response = { tables: [], total: 0 }
  }

  return {
    tables: response.tables ?? [],
  }
}

/** Column to sort rows by - any column key or system field */
export type RowsSortBy = string

function buildRowListSelectQuery(
  listSelectAttrKeys: string[] | null | undefined,
  sortBy: RowsSortBy,
  kind: DatabaseType,
): string | undefined {
  if (!listSelectAttrKeys?.length) return undefined
  const fields = new Set<string>([
    '$id',
    '$createdAt',
    '$updatedAt',
    '$permissions',
  ])
  if (kind === DatabaseType.Tablesdb) {
    fields.add('$sequence')
  }
  if (sortBy) fields.add(sortBy)
  for (const k of listSelectAttrKeys) {
    if (typeof k !== 'string') continue
    const t = k.trim()
    if (!t || t.startsWith('$') || t.length > 512) continue
    fields.add(t)
  }
  return Query.select([...fields])
}

/**
 * Query function to fetch rows for a table
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @param order - Sort direction
 * @param sortBy - Column to sort by
 * @param filterQueries - Optional filter query strings
 * @param listSelectAttrKeys - When set (tables/documents/vectors), adds `Query.select` so only these attributes plus system fields are returned
 * @returns Paginated rows with total count
 */
export async function fetchProjectTableRows(
  projectId: string,
  databaseId: string,
  tableId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  _search?: string,
  order: 'asc' | 'desc' = 'desc',
  sortBy: RowsSortBy = '$createdAt',
  filterQueries?: string[],
  listSelectAttrKeys?: string[] | null,
) {
  // listRows/listDocuments have no top-level search param; use filterQueries instead.
  void _search

  if (!projectId || !databaseId || !tableId) {
    return { rows: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)

  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  const selectQuery = buildRowListSelectQuery(
    listSelectAttrKeys,
    sortBy,
    kind,
  )
  const queries = [
    ...(selectQuery ? [selectQuery] : []),
    ...(filterQueries ?? []),
    order === 'asc' ? Query.orderAsc(sortBy) : Query.orderDesc(sortBy),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  if (kind === DatabaseType.Documentsdb || kind === DatabaseType.Vectorsdb) {
    const listFn =
      kind === DatabaseType.Documentsdb
        ? projectSdk.documentsDB.listDocuments.bind(projectSdk.documentsDB)
        : projectSdk.vectorsDB.listDocuments.bind(projectSdk.vectorsDB)
    try {
      const response = await listFn({
        databaseId,
        collectionId: tableId,
        queries,
        total: true,
      })
      const docs = (response.documents ?? []) as Record<string, unknown>[]
      return {
        rows: docs.map((d) => flattenDocumentForTableRow(d)),
        total: response.total ?? 0,
      }
    } catch {
      return { rows: [], total: 0 }
    }
  }

  let response: { rows?: unknown[]; documents?: unknown[]; total?: number }
  try {
    response = await projectSdk.tablesDB.listRows({
      databaseId,
      tableId,
      queries,
      total: true,
    })
  } catch {
    response = { rows: [], total: 0 }
  }

  return {
    rows: response.rows || response.documents || [],
    total: response.total || 0,
  }
}

/**
 * Fetch a single row by ID. Used when opening the row drawer from a shared link (hash).
 */
export async function fetchProjectTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
  rowId: string,
) {
  if (!projectId || !databaseId || !tableId || !rowId) {
    return null
  }
  const projectSdk = sdk.forProject(projectId)
  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  try {
    if (kind === DatabaseType.Documentsdb) {
      const doc = await projectSdk.documentsDB.getDocument({
        databaseId,
        collectionId: tableId,
        documentId: rowId,
      })
      return flattenDocumentForTableRow(doc as Record<string, unknown>)
    }
    if (kind === DatabaseType.Vectorsdb) {
      const doc = await projectSdk.vectorsDB.getDocument({
        databaseId,
        collectionId: tableId,
        documentId: rowId,
      })
      return flattenDocumentForTableRow(doc as Record<string, unknown>)
    }
    return await projectSdk.tablesDB.getRow({
      databaseId,
      tableId,
      rowId,
    })
  } catch {
    /* not found or no permission */
  }
  return null
}

/**
 * Query function to fetch columns (attributes) for a table via listColumns.
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param filterQueries - Optional list of Appwrite Query condition strings (from table filters)
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @returns Columns data with total count
 */
export async function fetchProjectTableColumns(
  projectId: string,
  databaseId: string,
  tableId: string,
  filterQueries?: string[],
  page: number = 0,
  limit: number = COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
) {
  if (!projectId || !databaseId || !tableId) {
    return { columns: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    ...(filterQueries ?? []),
    Query.orderAsc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  if (kind === DatabaseType.Documentsdb) {
    try {
      const coll = await projectSdk.documentsDB.getCollection({
        databaseId,
        collectionId: tableId,
      })
      const cols = mapCollectionAttributesToColumnLike(coll.attributes)
      return { columns: cols, total: cols.length }
    } catch {
      return { columns: [], total: 0 }
    }
  }

  if (kind === DatabaseType.Vectorsdb) {
    try {
      const coll = await projectSdk.vectorsDB.getCollection({
        databaseId,
        collectionId: tableId,
      })
      const cols = mapCollectionAttributesToColumnLike(coll.attributes)
      return { columns: cols, total: cols.length }
    } catch {
      return { columns: [], total: 0 }
    }
  }

  try {
    const response = await projectSdk.tablesDB.listColumns({
      databaseId,
      tableId,
      queries,
      total: true,
    })
    return {
      columns: response.columns ?? [],
      total: response.total ?? 0,
    }
  } catch {
    return { columns: [], total: 0 }
  }
}

/**
 * Query function to fetch indexes for a table via listIndexes.
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param filterQueries - Optional list of Appwrite Query condition strings (from table filters)
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @returns Indexes data with total count
 */
export async function fetchProjectTableIndexes(
  projectId: string,
  databaseId: string,
  tableId: string,
  filterQueries?: string[],
  page: number = 0,
  limit: number = COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
) {
  if (!projectId || !databaseId || !tableId) {
    return { indexes: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    ...(filterQueries ?? []),
    Query.orderAsc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  if (kind === DatabaseType.Documentsdb) {
    try {
      const response = await projectSdk.documentsDB.listIndexes({
        databaseId,
        collectionId: tableId,
        queries,
        total: true,
      })
      return {
        indexes: normalizeIndexesForTableUi(
          response.indexes as Record<string, unknown>[] | undefined,
        ),
        total: response.total ?? 0,
      }
    } catch {
      return { indexes: [], total: 0 }
    }
  }

  if (kind === DatabaseType.Vectorsdb) {
    try {
      const response = await projectSdk.vectorsDB.listIndexes({
        databaseId,
        collectionId: tableId,
        queries,
        total: true,
      })
      return {
        indexes: normalizeIndexesForTableUi(
          response.indexes as Record<string, unknown>[] | undefined,
        ),
        total: response.total ?? 0,
      }
    } catch {
      return { indexes: [], total: 0 }
    }
  }

  try {
    const response = await projectSdk.tablesDB.listIndexes({
      databaseId,
      tableId,
      queries,
      total: true,
    })
    return {
      indexes: response.indexes ?? [],
      total: response.total ?? 0,
    }
  } catch {
    return { indexes: [], total: 0 }
  }
}

/**
 * Query function to fetch a single table by ID
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @returns Table data or null
 */
export async function fetchProjectTable(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  if (!projectId || !databaseId || !tableId) {
    return null
  }

  const projectSdk = sdk.forProject(projectId)
  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  try {
    let response: Record<string, unknown> | null = null

    if (kind === DatabaseType.Documentsdb) {
      response = (await projectSdk.documentsDB.getCollection({
        databaseId,
        collectionId: tableId,
      })) as unknown as Record<string, unknown>
    } else if (kind === DatabaseType.Vectorsdb) {
      response = (await projectSdk.vectorsDB.getCollection({
        databaseId,
        collectionId: tableId,
      })) as unknown as Record<string, unknown>
    } else {
      response = (await projectSdk.tablesDB.getTable({
        databaseId,
        tableId,
      })) as unknown as Record<string, unknown>
    }

    const rowSecurity =
      response.rowSecurity === true || response.documentSecurity === true

    return {
      $id: response.$id as string,
      name: (response.name as string) || 'Unnamed Table',
      databaseId: databaseId,
      enabled: response.enabled !== false,
      rowSecurity,
      $permissions: (response.$permissions as string[]) || [],
      $createdAt:
        (response.$createdAt as string) || new Date().toISOString(),
      $updatedAt:
        (response.$updatedAt as string) ||
        (response.$createdAt as string) ||
        new Date().toISOString(),
      dimension:
        typeof response.dimension === 'number' ? response.dimension : undefined,
    }
  } catch {
    return null
  }
}

// ============================================================================
// MUTATION FUNCTIONS
// ============================================================================

/**
 * Delete a single row from a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param rowId - The row ID to delete
 */
export async function deleteProjectTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
  rowId: string,
) {
  if (!projectId || !databaseId || !tableId || !rowId) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  if (kind === DatabaseType.Documentsdb) {
    await projectSdk.documentsDB.deleteDocument({
      databaseId,
      collectionId: tableId,
      documentId: rowId,
    })
    return
  }
  if (kind === DatabaseType.Vectorsdb) {
    await projectSdk.vectorsDB.deleteDocument({
      databaseId,
      collectionId: tableId,
      documentId: rowId,
    })
    return
  }

  await projectSdk.tablesDB.deleteRow({
    databaseId,
    tableId,
    rowId,
  })
}

/**
 * Appwrite returns "The document data is missing..." when `data` is an empty object.
 * Fill attribute keys from the collection schema (null / defaults / type empties) so
 * duplicate, create-with-empty-JSON, etc. still succeed. Skips relationship attributes.
 */
async function ensureDocumentOrVectorCreateDataPopulated(
  projectId: string,
  databaseId: string,
  tableId: string,
  payloadWithoutId: Record<string, unknown>,
): Promise<Record<string, unknown>> {
  if (Object.keys(payloadWithoutId).length > 0) return payloadWithoutId

  const { columns } = await fetchProjectTableColumns(
    projectId,
    databaseId,
    tableId,
  )
  const filled: Record<string, unknown> = {}
  for (const col of columns) {
    const c = col as Record<string, unknown>
    const key = String(c.key || c.name || c.$id || c.attribute || '')
    if (!key || key.startsWith('$')) continue
    const type = String(c.type ?? 'string').toLowerCase()
    if (type === 'relationship') continue

    const isArray = Boolean(c.array)
    const required = Boolean(c.required)
    const def = c.default

    if (def !== undefined && def !== null) {
      filled[key] = def
    } else if (required) {
      if (type === 'boolean' || type === 'bool') filled[key] = false
      else if (
        type === 'integer' ||
        type === 'int' ||
        type === 'bigint' ||
        type === 'double' ||
        type === 'float' ||
        type === 'number'
      ) {
        filled[key] = 0
      } else if (isArray) {
        filled[key] = []
      } else {
        filled[key] = ''
      }
    } else {
      filled[key] = null
    }
  }
  return Object.keys(filled).length > 0 ? filled : payloadWithoutId
}

/**
 * Create a single row in a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param data - The row data (without $id, it will be generated)
 * @param rowId - Optional row ID (if not provided, will be generated)
 */
export async function createProjectTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
  data: Record<string, unknown>,
  rowId?: string,
  permissions?: string[],
) {
  if (!projectId || !databaseId || !tableId) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  const kind = await resolveProjectDatabaseType(projectId, databaseId)
  const { ID } = await import('@appwrite.io/console')
  const id = rowId || (data.$id as string) || ID.unique()

  let payload = { ...data } as Record<string, unknown>
  if (payload.$id) delete payload.$id

  if (kind === DatabaseType.Documentsdb) {
    payload = await ensureDocumentOrVectorCreateDataPopulated(
      projectId,
      databaseId,
      tableId,
      payload,
    )
    const created = await projectSdk.documentsDB.createDocument({
      databaseId,
      collectionId: tableId,
      documentId: id,
      data: payload as Record<string, any>,
      ...(permissions && permissions.length > 0 ? { permissions } : {}),
    })
    return flattenDocumentForTableRow(created as Record<string, unknown>)
  }

  if (kind === DatabaseType.Vectorsdb) {
    payload = await ensureDocumentOrVectorCreateDataPopulated(
      projectId,
      databaseId,
      tableId,
      payload,
    )
    const created = await projectSdk.vectorsDB.createDocument({
      databaseId,
      collectionId: tableId,
      documentId: id,
      data: payload as Record<string, any>,
      ...(permissions && permissions.length > 0 ? { permissions } : {}),
    })
    return flattenDocumentForTableRow(created as Record<string, unknown>)
  }

  return await projectSdk.tablesDB.createRow({
    databaseId,
    tableId,
    rowId: id,
    data: payload as Record<string, any>,
    ...(permissions && permissions.length > 0 ? { permissions } : {}),
  })
}

/**
 * Update a single row in a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param rowId - The row ID to update
 * @param data - The row data to update
 */
export async function updateProjectTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
  rowId: string,
  data: Record<string, unknown>,
  permissions?: string[],
) {
  if (!projectId || !databaseId || !tableId || !rowId) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  const payload = { ...data } as Record<string, unknown>
  if (payload.$id) delete payload.$id

  if (kind === DatabaseType.Documentsdb) {
    const updated = await projectSdk.documentsDB.updateDocument({
      databaseId,
      collectionId: tableId,
      documentId: rowId,
      data: payload as Record<string, any>,
      ...(permissions !== undefined ? { permissions } : {}),
    })
    return flattenDocumentForTableRow(updated as Record<string, unknown>)
  }

  if (kind === DatabaseType.Vectorsdb) {
    const updated = await projectSdk.vectorsDB.updateDocument({
      databaseId,
      collectionId: tableId,
      documentId: rowId,
      data: payload as Record<string, any>,
      ...(permissions !== undefined ? { permissions } : {}),
    })
    return flattenDocumentForTableRow(updated as Record<string, unknown>)
  }

  return await projectSdk.tablesDB.updateRow({
    databaseId,
    tableId,
    rowId,
    data: payload as Record<string, any>,
    ...(permissions !== undefined ? { permissions } : {}),
  })
}

/**
 * Create multiple rows in a table
 * Uses bulk insert if available and no relationship columns exist, otherwise inserts one-by-one
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param rows - Array of row data objects
 * @param hasRelationshipColumns - Whether the table has relationship columns
 */
export async function createProjectTableRows(
  projectId: string,
  databaseId: string,
  tableId: string,
  rows: Record<string, unknown>[],
  hasRelationshipColumns: boolean = false,
) {
  if (!projectId || !databaseId || !tableId) {
    throw new Error('Missing required parameters')
  }

  if (rows.length === 0) {
    return { created: 0, errors: [] }
  }

  const projectSdk = sdk.forProject(projectId)
  const errors: Error[] = []
  let created = 0

  // If no relationship columns, try bulk insert
  if (!hasRelationshipColumns) {
    try {
      const { ID } = await import('@appwrite.io/console')
      const rowsToInsert = rows.map((row) => {
        const rowRec = row as Record<string, unknown>
        const rowData = { ...rowRec }
        const rowId =
          typeof rowRec.$id === 'string' ? rowRec.$id : ID.unique()
        if (rowData.$id) {
          delete rowData.$id
        }
        return {
          $id: rowId,
          ...rowData,
        }
      })

      await projectSdk.tablesDB.createRows({
        databaseId,
        tableId,
        rows: rowsToInsert,
      })
      created = rows.length
    } catch {
      // If bulk insert fails, fall back to individual inserts
    }
  }

  // If bulk insert didn't work or we have relationship columns, insert one-by-one
  if (created === 0) {
    // Insert in small batches of 10 to avoid overwhelming the API
    const batchSize = 10
    for (let i = 0; i < rows.length; i += batchSize) {
      const batch = rows.slice(i, i + batchSize)
      const batchPromises = batch.map(async (row) => {
        try {
          const rid = (row as Record<string, unknown>).$id
          await createProjectTableRow(
            projectId,
            databaseId,
            tableId,
            row,
            typeof rid === 'string' ? rid : undefined,
          )
          created++
        } catch (error) {
          // Ignore individual row errors for sample data
          errors.push(error instanceof Error ? error : new Error(String(error)))
        }
      })
      await Promise.all(batchPromises)
    }
  }

  return { created, errors }
}

/**
 * Create a column (attribute) in a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param columnData - The column data to create
 */
export async function createProjectTableColumn(
  projectId: string,
  databaseId: string,
  tableId: string,
  columnData: unknown,
) {
  if (!projectId || !databaseId || !tableId) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  const data = columnData as Record<string, unknown>
  const type = data.type
  const required = data.required === true
  const array = data.array === true
  const encrypt = data.encrypt === true
  const size = typeof data.size === 'number' ? data.size : 255
  const min = typeof data.min === 'number' ? data.min : undefined
  const max = typeof data.max === 'number' ? data.max : undefined
  const stringDefault =
    data.xdefault === undefined || data.xdefault === null
      ? undefined
      : String(data.xdefault)
  const numberDefault =
    typeof data.xdefault === 'number' || typeof data.xdefault === 'bigint'
      ? data.xdefault
      : undefined
  const floatDefault =
    typeof data.xdefault === 'number' ? data.xdefault : undefined
  const boolDefault =
    typeof data.xdefault === 'boolean' ? data.xdefault : undefined
  const elements = Array.isArray(data.elements)
    ? data.elements.map(String)
    : []
  const colKey =
    typeof data.key === 'string' ? data.key : String(data.key ?? '')
  if (!colKey) {
    throw new Error('Column key is required')
  }

  // Call the appropriate method based on column type (TablesDB: createXColumn with databaseId, tableId, key, ...)
  switch (type) {
    case 'varchar':
      return await projectSdk.tablesDB.createVarcharColumn({
        databaseId,
        tableId,
        key: colKey,
        size,
        required,
        xdefault: stringDefault,
        array,
        encrypt,
      })
    case 'text':
      return await projectSdk.tablesDB.createTextColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault: stringDefault,
        array,
        encrypt,
      })
    case 'mediumtext':
      return await projectSdk.tablesDB.createMediumtextColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault: stringDefault,
        array,
        encrypt,
      })
    case 'longtext':
      return await projectSdk.tablesDB.createLongtextColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault: stringDefault,
        array,
        encrypt,
      })
    case 'string':
      return await projectSdk.tablesDB.createStringColumn({
        databaseId,
        tableId,
        key: colKey,
        size,
        required,
        xdefault: stringDefault,
        array,
        encrypt,
      })
    case 'integer':
      return await projectSdk.tablesDB.createIntegerColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        min,
        max,
        xdefault: numberDefault,
        array,
      })
    case 'bigint':
      return await projectSdk.tablesDB.createBigIntColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        min,
        max,
        xdefault: numberDefault,
        array,
      })
    case 'double':
    case 'float':
      return await projectSdk.tablesDB.createFloatColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        min,
        max,
        xdefault: floatDefault,
        array,
      })
    case 'boolean':
      return await projectSdk.tablesDB.createBooleanColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault: boolDefault,
        array,
      })
    case 'datetime':
      return await projectSdk.tablesDB.createDatetimeColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault: stringDefault,
        array,
      })
    case 'email':
      return await projectSdk.tablesDB.createEmailColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault: stringDefault,
        array,
      })
    case 'ip':
      return await projectSdk.tablesDB.createIpColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault: stringDefault,
        array,
      })
    case 'url':
      return await projectSdk.tablesDB.createUrlColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault: stringDefault,
        array,
      })
    case 'enum':
      return await projectSdk.tablesDB.createEnumColumn({
        databaseId,
        tableId,
        key: colKey,
        elements,
        required,
        xdefault: stringDefault,
        array,
      })
    case 'relationship':
      return await projectSdk.tablesDB.createRelationshipColumn({
        databaseId,
        tableId,
        relatedTableId: String(data.relatedTableId ?? ''),
        type: data.relationshipType as RelationshipType,
        twoWay: data.twoWay === true,
        key: colKey,
        twoWayKey:
          typeof data.twoWayKey === 'string' ? data.twoWayKey : undefined,
        onDelete: data.onDelete as RelationMutate | undefined,
      })
    case 'point':
      return await projectSdk.tablesDB.createPointColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault: data.xdefault as number[] | undefined,
      })
    case 'linestring':
      return await projectSdk.tablesDB.createLineColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault: data.xdefault as number[][] | undefined,
      })
    case 'polygon':
      return await projectSdk.tablesDB.createPolygonColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault: data.xdefault as number[][] | undefined,
      })
    default:
      throw new Error(`Unsupported column type: ${type}`)
  }
}

/**
 * Update a column (attribute) in a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param columnKey - The column key to update
 * @param columnData - The column data to update
 */
export async function updateProjectTableColumn(
  projectId: string,
  databaseId: string,
  tableId: string,
  columnKey: string,
  columnData: unknown,
) {
  if (!projectId || !databaseId || !tableId || !columnKey) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  const data = columnData as Record<string, unknown>
  const type = data.type
  const required = data.required === true
  const size = typeof data.size === 'number' ? data.size : undefined
  const min = typeof data.min === 'number' ? data.min : undefined
  const max = typeof data.max === 'number' ? data.max : undefined
  // Update column APIs require `xdefault` to be present. Pass `null` when there is
  // no default (SDK treats undefined as missing and throws).
  const stringDefault =
    data.xdefault === undefined || data.xdefault === null || data.xdefault === ''
      ? null
      : String(data.xdefault)
  const numberDefault =
    typeof data.xdefault === 'number' || typeof data.xdefault === 'bigint'
      ? data.xdefault
      : null
  const floatDefault =
    typeof data.xdefault === 'number' ? data.xdefault : null
  const boolDefault =
    typeof data.xdefault === 'boolean' ? data.xdefault : null
  const spatialDefault =
    data.xdefault === undefined || data.xdefault === null
      ? null
      : data.xdefault
  const elements = Array.isArray(data.elements)
    ? data.elements.map(String)
    : []
  const formKey = typeof data.key === 'string' ? data.key.trim() : ''
  const explicitNewKey =
    typeof data.newKey === 'string' ? data.newKey.trim() : ''
  const newKey =
    explicitNewKey ||
    (formKey && formKey !== columnKey ? formKey : undefined) ||
    undefined

  // Call the appropriate update method based on column type
  switch (type) {
    case 'varchar':
      return await projectSdk.tablesDB.updateVarcharColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault: stringDefault,
        size,
        newKey,
      })
    case 'text':
      return await projectSdk.tablesDB.updateTextColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault: stringDefault,
        newKey,
      })
    case 'mediumtext':
      return await projectSdk.tablesDB.updateMediumtextColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault: stringDefault,
        newKey,
      })
    case 'longtext':
      return await projectSdk.tablesDB.updateLongtextColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault: stringDefault,
        newKey,
      })
    case 'string':
      return await projectSdk.tablesDB.updateStringColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault: stringDefault,
        size,
        newKey,
      })
    case 'integer':
      return await projectSdk.tablesDB.updateIntegerColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        min,
        max,
        xdefault: numberDefault,
        newKey,
      })
    case 'bigint':
      return await projectSdk.tablesDB.updateBigIntColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        min,
        max,
        xdefault: numberDefault,
        newKey,
      })
    case 'double':
    case 'float':
      return await projectSdk.tablesDB.updateFloatColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        min,
        max,
        xdefault: floatDefault,
        newKey,
      })
    case 'boolean':
      return await projectSdk.tablesDB.updateBooleanColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault: boolDefault,
        newKey,
      })
    case 'datetime':
      return await projectSdk.tablesDB.updateDatetimeColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault: stringDefault,
        newKey,
      })
    case 'email':
      return await projectSdk.tablesDB.updateEmailColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault: stringDefault,
        newKey,
      })
    case 'ip':
      return await projectSdk.tablesDB.updateIpColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault: stringDefault,
        newKey,
      })
    case 'url':
      return await projectSdk.tablesDB.updateUrlColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault: stringDefault,
        newKey,
      })
    case 'enum':
      return await projectSdk.tablesDB.updateEnumColumn({
        databaseId,
        tableId,
        key: columnKey,
        elements,
        required,
        xdefault: stringDefault,
        newKey,
      })
    case 'relationship':
      return await projectSdk.tablesDB.updateRelationshipColumn({
        databaseId,
        tableId,
        key: columnKey,
        onDelete: data.onDelete as RelationMutate | undefined,
        newKey,
      })
    case 'point':
      return await projectSdk.tablesDB.updatePointColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault: spatialDefault as number[] | null,
        newKey,
      })
    case 'linestring':
      return await projectSdk.tablesDB.updateLineColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault: spatialDefault as number[][] | null,
        newKey,
      })
    case 'polygon':
      return await projectSdk.tablesDB.updatePolygonColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault: spatialDefault as number[][] | null,
        newKey,
      })
    default:
      throw new Error(`Unsupported column type for update: ${type}`)
  }
}

/**
 * Delete a column (attribute) from a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param columnKey - The column key to delete
 */
export async function deleteProjectTableColumn(
  projectId: string,
  databaseId: string,
  tableId: string,
  columnKey: string,
) {
  if (!projectId || !databaseId || !tableId || !columnKey) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.tablesDB.deleteColumn({
    databaseId,
    tableId,
    key: columnKey,
  })
}

/**
 * Create an index in a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param indexData - The index data to create
 */
export async function createProjectTableIndex(
  projectId: string,
  databaseId: string,
  tableId: string,
  indexData: unknown,
) {
  if (!projectId || !databaseId || !tableId) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  const raw = indexData as Record<string, unknown>
  const key = raw.key as string
  const type = raw.type
  const columns = (raw.columns as string[]) || (raw.attributes as string[]) || []
  const orders = raw.orders as string[] | undefined
  const lengths = raw.lengths as number[] | undefined

  const orderBy = orders?.map((order) =>
    order === 'asc' ? OrderBy.Asc : OrderBy.Desc,
  )

  if (kind === DatabaseType.Documentsdb) {
    return await projectSdk.documentsDB.createIndex({
      databaseId,
      collectionId: tableId,
      key,
      type: type as DocumentsDBIndexType,
      attributes: columns,
      orders: orderBy,
      lengths,
    })
  }

  if (kind === DatabaseType.Vectorsdb) {
    return await projectSdk.vectorsDB.createIndex({
      databaseId,
      collectionId: tableId,
      key,
      type: type as VectorsDBIndexType,
      attributes: columns,
      orders: orderBy,
      lengths,
    })
  }

  return await projectSdk.tablesDB.createIndex({
    databaseId,
    tableId,
    key,
    type: type as TablesDBIndexType,
    columns,
    orders: orderBy,
    lengths,
  })
}

/**
 * Delete an index from a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param indexKey - The index key to delete
 */
export async function deleteProjectTableIndex(
  projectId: string,
  databaseId: string,
  tableId: string,
  indexKey: string,
) {
  if (!projectId || !databaseId || !tableId || !indexKey) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  if (kind === DatabaseType.Documentsdb) {
    return await projectSdk.documentsDB.deleteIndex({
      databaseId,
      collectionId: tableId,
      key: indexKey,
    })
  }

  if (kind === DatabaseType.Vectorsdb) {
    return await projectSdk.vectorsDB.deleteIndex({
      databaseId,
      collectionId: tableId,
      key: indexKey,
    })
  }

  return await projectSdk.tablesDB.deleteIndex({
    databaseId,
    tableId,
    key: indexKey,
  })
}

/**
 * Update a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param data - The table data to update (name, permissions, rowSecurity, enabled)
 */
export async function updateProjectTable(
  projectId: string,
  databaseId: string,
  tableId: string,
  data: {
    name: string
    permissions: string[]
    rowSecurity: boolean
    enabled: boolean
  },
) {
  if (!projectId || !databaseId || !tableId) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  const collectionPayload = {
    databaseId,
    collectionId: tableId,
    name: data.name,
    permissions: data.permissions,
    documentSecurity: data.rowSecurity,
    enabled: data.enabled,
  }

  if (kind === DatabaseType.Documentsdb) {
    return await projectSdk.documentsDB.updateCollection(collectionPayload)
  }

  if (kind === DatabaseType.Vectorsdb) {
    return await projectSdk.vectorsDB.updateCollection(collectionPayload)
  }

  return await projectSdk.tablesDB.updateTable({
    databaseId,
    tableId,
    name: data.name,
    permissions: data.permissions,
    rowSecurity: data.rowSecurity,
    enabled: data.enabled,
  })
}

/**
 * Delete a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 */
export async function deleteProjectTable(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  if (!projectId || !databaseId || !tableId) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  const kind = await resolveProjectDatabaseType(projectId, databaseId)

  if (kind === DatabaseType.Documentsdb) {
    return await projectSdk.documentsDB.deleteCollection({
      databaseId,
      collectionId: tableId,
    })
  }

  if (kind === DatabaseType.Vectorsdb) {
    return await projectSdk.vectorsDB.deleteCollection({
      databaseId,
      collectionId: tableId,
    })
  }

  return await projectSdk.tablesDB.deleteTable({
    databaseId,
    tableId,
  })
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching paginated databases for a project
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function databasesQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
) {
  return queryOptions({
    queryKey: [
      'databases',
      'project',
      projectId,
      page,
      limit,
      search,
      filterQueries,
    ],
    queryFn: () =>
      fetchProjectDatabases(projectId!, page, limit, search, filterQueries),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    placeholderData: keepPreviousData, // Keep showing previous list until new data is ready (page size/page/search change)
    // Don't keep disabled queries in cache
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for a single product database list (TablesDB, DocumentsDB, or VectorsDB).
 */
export function productDatabasesQueryOptions(
  projectId: string | null | undefined,
  backend: DatabaseType,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
) {
  return queryOptions({
    queryKey: [
      'databases',
      'project',
      projectId,
      backend,
      page,
      limit,
      search,
      filterQueries,
    ],
    queryFn: () =>
      fetchProjectProductDatabases(
        projectId!,
        backend,
        page,
        limit,
        search,
        filterQueries,
      ),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for the Console unified database list
 * (`projectSdk.console.listDatabases`).
 */
export function consoleDatabasesQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
) {
  return queryOptions({
    queryKey: [
      'databases',
      'project',
      projectId,
      'console',
      page,
      limit,
      search,
      filterQueries,
    ],
    queryFn: () =>
      fetchProjectConsoleDatabases(
        projectId!,
        page,
        limit,
        search,
        filterQueries,
      ),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function mapProjectDatabaseListItems(
  databasesData:
    | { databases?: Models.Database[]; total?: number }
    | undefined,
  limit: number,
) {
  const databases = (databasesData?.databases ?? []).map((db) => {
    const backupPolicies = db.policies ?? []
    const backupPolicyCount = backupPolicies.length
    const hasBackupPolicy = backupPolicyCount > 0
    const backupPolicy = backupPolicies[0] || null

    return {
      $id: db.$id,
      name: db.name || 'Unnamed Database',
      tables: 0,
      rows: 0,
      enabled: db.enabled !== false,
      createdAt: db.$createdAt || new Date().toISOString(),
      updatedAt: db.$updatedAt || db.$createdAt || new Date().toISOString(),
      hasBackupPolicy,
      backupPolicy,
      backupPolicyCount,
      databaseType: coerceDatabaseType(db.type),
      // Preserve the API `type` for compute detection (native engines coerce to TablesDB).
      apiType: db.type,
      status: readProductDatabaseLifecycleStatus(db),
      replicas: typeof db.replicas === 'number' ? db.replicas : null,
      specification: readProductDatabaseSpecification(db),
    } as Database & {
      enabled: boolean
      createdAt: string
      updatedAt: string
      hasBackupPolicy: boolean
      backupPolicy: unknown
      backupPolicyCount: number
      databaseType?: DatabaseType
      apiType?: string
      status: string | null
      replicas: number | null
      specification: string | null
    }
  })

  const totalPages = databasesData?.total
    ? Math.ceil(databasesData.total / limit)
    : 0

  return {
    databases,
    total: databasesData?.total || 0,
    totalPages,
  }
}

/**
 * Query options for fetching paginated tables for a database
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function tablesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  order: 'asc' | 'desc' = 'asc',
  sortBy: TablesSortBy = '$createdAt',
  routeKindHint?: DatabaseRouteKind,
) {
  // Normalize search to undefined if empty string for consistent query keys
  const normalizedSearch = search?.trim() || undefined

  return queryOptions({
    queryKey: [
      'tables',
      'project',
      projectId,
      databaseId,
      page,
      limit,
      normalizedSearch,
      order,
      sortBy,
    ],
    queryFn: () =>
      fetchProjectTables(
        projectId!,
        databaseId!,
        page,
        limit,
        normalizedSearch,
        order,
        sortBy,
        routeKindHint,
      ),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    placeholderData: keepPreviousData, // Keep showing previous list until new data is ready (page size/page/search change)
    // Don't keep disabled queries in cache
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching paginated rows for a table
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function tableRowsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  order: 'asc' | 'desc' = 'desc',
  sortBy: RowsSortBy = '$createdAt',
  filterQueries?: string[],
  listSelectAttrKeys?: string[] | null,
) {
  const normalizedSearch = search?.trim() || undefined
  const listSelectKey =
    (listSelectAttrKeys?.length ?? 0) > 0
      ? [...listSelectAttrKeys!].sort().join('\u0001')
      : null

  return queryOptions({
    queryKey: [
      'rows',
      'project',
      projectId,
      databaseId,
      tableId,
      page,
      limit,
      normalizedSearch,
      order,
      sortBy,
      filterQueries,
      listSelectKey,
    ],
    queryFn: () =>
      fetchProjectTableRows(
        projectId!,
        databaseId!,
        tableId!,
        page,
        limit,
        normalizedSearch,
        order,
        sortBy,
        filterQueries,
        listSelectAttrKeys,
      ),
    enabled: !!projectId && !!databaseId && !!tableId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    placeholderData: keepPreviousData, // Keep showing previous list until new data is ready (page size/page/search change)
    // Don't keep disabled queries in cache
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching a single database by ID
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function databaseQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  routeKindHint?: DatabaseRouteKind,
) {
  return queryOptions({
    queryKey: ['database', 'project', projectId, databaseId],
    queryFn: () => fetchProjectDatabase(projectId!, databaseId!, routeKindHint),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export type TableColumnsListCache = {
  columns: unknown[]
  total: number
}

/** Partial query key for all paginated/filtered column list queries for a table. */
export function projectTableColumnsQueryKeyPrefix(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  return ['columns', 'project', projectId, databaseId, tableId] as const
}

/**
 * Patch every cached column list for a table (all pages/filters).
 * Used for optimistic status updates after delete/create.
 */
export function patchProjectTableColumnsCache(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
  patch: (columns: unknown[]) => unknown[],
) {
  const prefix = projectTableColumnsQueryKeyPrefix(
    projectId,
    databaseId,
    tableId,
  )
  queryClient.setQueriesData<TableColumnsListCache>(
    { queryKey: prefix },
    (old) => {
      if (!old?.columns) return old
      const nextColumns = patch(old.columns)
      return { ...old, columns: nextColumns }
    },
  )
}

/** Refetch all column list queries for a table (active + inactive cache entries). */
export async function refetchProjectTableColumnsQueries(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const prefix = projectTableColumnsQueryKeyPrefix(
    projectId,
    databaseId,
    tableId,
  )
  await queryClient.refetchQueries({ queryKey: prefix, type: 'all' })
}

export async function refetchProjectTableRelatedQueries(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  await refetchProjectTableColumnsQueries(
    queryClient,
    projectId,
    databaseId,
    tableId,
  )
  await queryClient.refetchQueries({
    queryKey: ['indexes', 'project', projectId, databaseId, tableId],
    type: 'all',
  })
  await queryClient.refetchQueries({
    queryKey: ['table', 'project', projectId, databaseId, tableId],
    type: 'active',
  })
  await queryClient.refetchQueries({
    queryKey: ['tables', 'project', projectId, databaseId],
    type: 'active',
  })
}

/**
 * Query options for fetching columns (attributes) for a table
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 *
 * @param filterQueries - Optional list of Appwrite Query condition strings (from table filters)
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 */
export function tableColumnsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  filterQueries?: string[],
  page: number = 0,
  limit: number = COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
) {
  const hasFilters = filterQueries !== undefined && filterQueries.length > 0
  return queryOptions({
    queryKey: [
      'columns',
      'project',
      projectId,
      databaseId,
      tableId,
      ...(hasFilters ? [filterQueries] : []),
      page,
      limit,
    ],
    queryFn: () =>
      fetchProjectTableColumns(
        projectId!,
        databaseId!,
        tableId!,
        filterQueries,
        page,
        limit,
      ),
    enabled: !!projectId && !!databaseId && !!tableId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    // When columns are invalidated while this observer is inactive (e.g. user on schema tab),
    // remounting the rows view must refetch stale cache; false would keep outdated columns
    // until a full reload.
    refetchOnMount: true,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData, // Keep showing previous list until new data is ready (page size/page change)
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching a single table by ID
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function tableQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['table', 'project', projectId, databaseId, tableId],
    queryFn: () => fetchProjectTable(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching indexes for a table
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 *
 * @param filterQueries - Optional list of Appwrite Query condition strings (from table filters)
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 */
export function tableIndexesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  filterQueries?: string[],
  page: number = 0,
  limit: number = COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
) {
  const hasFilters = filterQueries !== undefined && filterQueries.length > 0
  return queryOptions({
    queryKey: [
      'indexes',
      'project',
      projectId,
      databaseId,
      tableId,
      ...(hasFilters ? [filterQueries] : []),
      page,
      limit,
    ],
    queryFn: () =>
      fetchProjectTableIndexes(
        projectId!,
        databaseId!,
        tableId!,
        filterQueries,
        page,
        limit,
      ),
    enabled: !!projectId && !!databaseId && !!tableId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData, // Keep showing previous list until new data is ready (page size/page change)
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching all tables with full details for visualizer
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function allTablesForVisualizerQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['tables', 'visualizer', 'project', projectId, databaseId],
    queryFn: () => fetchAllProjectTablesForVisualizer(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch databases for a project
 *
 * This is useful for displaying project databases with pagination and search.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated databases with loading state
 */
export function useProjectDatabases(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
) {
  const {
    data: databasesData,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  } = useQuery(
    databasesQueryOptions(projectId, page, limit, search, filterQueries),
  )

  const mapped = useMemo(
    () => mapProjectDatabaseListItems(databasesData, limit),
    [databasesData, limit],
  )

  return {
    databases: mapped.databases,
    total: mapped.total,
    totalPages: mapped.totalPages,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  }
}

/**
 * Hook to fetch paginated databases for a single product API.
 */
export function useProjectProductDatabases(
  projectId: string | null | undefined,
  backend: DatabaseType,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
) {
  const {
    data: databasesData,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  } = useQuery(
    productDatabasesQueryOptions(
      projectId,
      backend,
      page,
      limit,
      search,
      filterQueries,
    ),
  )

  const mapped = useMemo(
    () => mapProjectDatabaseListItems(databasesData, limit),
    [databasesData, limit],
  )

  return {
    databases: mapped.databases,
    total: mapped.total,
    totalPages: mapped.totalPages,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  }
}

/**
 * Hook to fetch the Console unified database list for a project.
 */
export function useProjectConsoleDatabases(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
) {
  const {
    data: databasesData,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  } = useQuery(
    consoleDatabasesQueryOptions(
      projectId,
      page,
      limit,
      search,
      filterQueries,
    ),
  )

  const mapped = useMemo(
    () => mapProjectDatabaseListItems(databasesData, limit),
    [databasesData, limit],
  )

  return {
    databases: mapped.databases,
    total: mapped.total,
    totalPages: mapped.totalPages,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a single database by ID
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @returns Single database with loading state
 */
export function useProjectDatabase(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  routeKindHint?: DatabaseRouteKind,
) {
  const {
    data: databaseData,
    isLoading,
    isPending,
    error,
    refetch,
  } = useQuery(databaseQueryOptions(projectId, databaseId, routeKindHint))

  return {
    database: databaseData || null,
    isLoading: isLoading && !databaseData, // Only loading if no data yet
    isPending,
    error,
    refetch,
  }
}

/**
 * Hook to fetch tables (collections) for a database
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated tables with loading state
 */
export function useProjectTables(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  order: 'asc' | 'desc' = 'asc',
  sortBy: TablesSortBy = '$createdAt',
  routeKindHint?: DatabaseRouteKind,
) {
  // Normalize search to undefined if empty string for consistent query keys
  const normalizedSearch = search?.trim() || undefined

  const {
    data: tablesData,
    isLoading,
    isFetching,
    isPending,
    error,
    refetch,
  } = useQuery(
    tablesQueryOptions(
      projectId,
      databaseId,
      page,
      limit,
      normalizedSearch,
      order,
      sortBy,
      routeKindHint,
    ),
  )

  // Map tables to our Collection type
  const tables = useMemo(() => {
    if (!tablesData?.tables) return []

    return tablesData.tables.map((table: unknown) => {
      const t = table as Record<string, unknown>
      const attrs = t.attributes as unknown[] | undefined
      const idxs = t.indexes as unknown[] | undefined
      return {
        $id: t.$id as string,
        name: (t.name as string) || 'Unnamed Table',
        databaseId: databaseId || '',
        rows: (t.total as number) || 0,
        columns: attrs?.length || 0,
        indexes: idxs?.length || 0,
        enabled: t.enabled !== false,
      }
    }) as Collection[]
  }, [tablesData, databaseId])

  const totalPages = useMemo(() => {
    if (!tablesData?.total) return 0
    return Math.ceil(tablesData.total / limit)
  }, [tablesData?.total, limit])

  return {
    tables,
    total: tablesData?.total || 0,
    totalPages,
    isLoading: isLoading && !tablesData, // Only loading if no data yet
    isFetching,
    isPending,
    error,
    refetch,
  }
}

/**
 * Hook to fetch all tables with full details (columns, indexes) for visualizer
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @returns All tables with columns and indexes
 */
export function useAllProjectTablesForVisualizer(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const {
    data: tablesData,
    isLoading,
    isPending,
    error,
    refetch,
  } = useQuery(allTablesForVisualizerQueryOptions(projectId, databaseId))

  return {
    tables: tablesData?.tables ?? [],
    isLoading: isLoading && !tablesData, // Only loading if no data yet
    isPending,
    error,
    refetch,
  }
}

/**
 * Hook to fetch rows for a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @param order - Sort direction
 * @param sortBy - Column to sort by
 * @returns Paginated rows with loading state
 */
export function useProjectTableRows(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  order: 'asc' | 'desc' = 'desc',
  sortBy: RowsSortBy = '$createdAt',
  filterQueries?: string[],
  listSelectAttrKeys?: string[] | null,
) {
  const normalizedSearch = search?.trim() || undefined

  const {
    data: rowsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(
    tableRowsQueryOptions(
      projectId,
      databaseId,
      tableId,
      page,
      limit,
      normalizedSearch,
      order,
      sortBy,
      filterQueries,
      listSelectAttrKeys,
    ),
  )

  const totalPages = useMemo(() => {
    if (!rowsData?.total) return 0
    return Math.ceil(rowsData.total / limit)
  }, [rowsData?.total, limit])

  return {
    rows: rowsData?.rows || [],
    total: rowsData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to fetch columns (attributes) for a table via listColumns
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param filterQueries - Optional list of Appwrite Query condition strings (from table filters)
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @returns Columns with loading state and total count
 */
export function useProjectTableColumns(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  filterQueries?: string[],
  page: number = 0,
  limit: number = COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
) {
  const {
    data: columnsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(
    tableColumnsQueryOptions(
      projectId,
      databaseId,
      tableId,
      filterQueries,
      page,
      limit,
    ),
  )

  return {
    columns: columnsData?.columns || [],
    total: columnsData?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to fetch indexes for a table via listIndexes
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @param filterQueries - Optional list of Appwrite Query condition strings (from table filters)
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @returns Indexes with loading state and total count
 */
export function useProjectTableIndexes(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  filterQueries?: string[],
  page: number = 0,
  limit: number = COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
) {
  const {
    data: indexesData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(
    tableIndexesQueryOptions(
      projectId,
      databaseId,
      tableId,
      filterQueries,
      page,
      limit,
    ),
  )

  return {
    indexes: indexesData?.indexes || [],
    total: indexesData?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Same React Query cache as {@link tableColumnsQueryOptions}; fetches collection
 * attributes for Documents/Vectors via {@link fetchProjectTableColumns} (not
 * `tablesDB.listColumns`). Use from Documents/Vectors UI instead of table-named exports.
 */
export const collectionAttributesQueryOptions = tableColumnsQueryOptions

/**
 * Collection schema fields for Documents DB / Vectors DB collections (attributes),
 * with the same return shape as {@link useProjectTableColumns} for row UI compatibility.
 */
export function useProjectCollectionAttributes(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  filterQueries?: string[],
  page: number = 0,
  limit: number = COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
) {
  return useProjectTableColumns(
    projectId,
    databaseId,
    tableId,
    filterQueries,
    page,
    limit,
  )
}

/** Same cache as {@link tableIndexesQueryOptions}; use from Documents/Vectors UI. */
export const collectionIndexesQueryOptions = tableIndexesQueryOptions

/**
 * Collection indexes for Documents/Vectors (same shape as {@link useProjectTableIndexes}).
 */
export function useProjectCollectionIndexes(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  filterQueries?: string[],
  page: number = 0,
  limit: number = COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
) {
  return useProjectTableIndexes(
    projectId,
    databaseId,
    tableId,
    filterQueries,
    page,
    limit,
  )
}

/**
 * Hook to fetch a single table by ID
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @returns Single table with loading state
 */
export function useProjectTable(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const {
    data: tableData,
    isLoading,
    isPending,
    error,
    refetch,
  } = useQuery(tableQueryOptions(projectId, databaseId, tableId))

  return {
    table: tableData || null,
    isLoading: isLoading && !tableData, // Only loading if no data yet
    isPending,
    error,
    refetch,
  }
}

/**
 * Hook to create a row in a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 */
export function useCreateProjectTableRow(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      data,
      rowId,
      permissions,
    }: {
      data: Record<string, unknown>
      rowId?: string
      permissions?: string[]
    }) => {
      if (!projectId || !databaseId || !tableId) {
        throw new Error('Missing required parameters')
      }
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return await createProjectTableRow(
        projectId,
        databaseId,
        tableId,
        data,
        rowId,
        permissions,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
    },
  })
}

/**
 * Hook to update a row in a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 */
export function useUpdateProjectTableRow(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      rowId,
      data,
      permissions,
    }: {
      rowId: string
      data: Record<string, unknown>
      permissions?: string[]
    }) => {
      if (!projectId || !databaseId || !tableId || !rowId) {
        throw new Error('Missing required parameters')
      }
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return await updateProjectTableRow(
        projectId,
        databaseId,
        tableId,
        rowId,
        data,
        permissions,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

/**
 * Hook to delete a row from a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 */
export function useDeleteProjectTableRow(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (rowId: string) => {
      if (!projectId || !databaseId || !tableId || !rowId) {
        throw new Error('Missing required parameters')
      }
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return await deleteProjectTableRow(projectId, databaseId, tableId, rowId)
    },
    onSuccess: async () => {
      // Refetch rows list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
    },
  })
}

/**
 * Hook to create multiple rows in a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 */
export function useCreateProjectTableRows(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      rows,
      hasRelationshipColumns = false,
    }: {
      rows: Record<string, unknown>[]
      hasRelationshipColumns?: boolean
    }) => {
      if (!projectId || !databaseId || !tableId) {
        throw new Error('Missing required parameters')
      }
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return await createProjectTableRows(
        projectId,
        databaseId,
        tableId,
        rows,
        hasRelationshipColumns,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
    },
  })
}

/**
 * Hook to create a column in a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 */
export function useCreateProjectTableColumn(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (columnData: unknown) => {
      if (!projectId || !databaseId || !tableId) {
        throw new Error('Missing required parameters')
      }
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return await createProjectTableColumn(
        projectId,
        databaseId,
        tableId,
        columnData,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['columns', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      queryClient.invalidateQueries({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

/**
 * Hook to update a column in a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 */
export function useUpdateProjectTableColumn(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      columnKey,
      columnData,
    }: {
      columnKey: string
      columnData: unknown
    }) => {
      if (!projectId || !databaseId || !tableId || !columnKey) {
        throw new Error('Missing required parameters')
      }
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return await updateProjectTableColumn(
        projectId,
        databaseId,
        tableId,
        columnKey,
        columnData,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['columns', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      queryClient.invalidateQueries({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

/**
 * Hook to delete a column from a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 */
export function useDeleteProjectTableColumn(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (columnKey: string) => {
      if (!projectId || !databaseId || !tableId || !columnKey) {
        throw new Error('Missing required parameters')
      }
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return await deleteProjectTableColumn(
        projectId,
        databaseId,
        tableId,
        columnKey,
      )
    },
    onMutate: async (columnKey: string) => {
      if (!projectId || !databaseId || !tableId) return
      await queryClient.cancelQueries({
        queryKey: projectTableColumnsQueryKeyPrefix(
          projectId,
          databaseId,
          tableId,
        ),
      })
      patchProjectTableColumnsCache(
        queryClient,
        projectId,
        databaseId,
        tableId,
        (columns) =>
          columns.map((col) => {
            const c = col as Record<string, unknown>
            const key = String(c.key ?? c.name ?? c.$id ?? '')
            if (key === columnKey) {
              return { ...c, status: 'deleting' }
            }
            return col
          }),
      )
    },
    onSuccess: async () => {
      if (!projectId || !databaseId || !tableId) return
      await refetchProjectTableRelatedQueries(
        queryClient,
        projectId,
        databaseId,
        tableId,
      )
    },
  })
}

/**
 * Hook to create an index in a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 */
export function useCreateProjectTableIndex(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (indexData: unknown) => {
      if (!projectId || !databaseId || !tableId) {
        throw new Error('Missing required parameters')
      }
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return await createProjectTableIndex(
        projectId,
        databaseId,
        tableId,
        indexData,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['indexes', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      queryClient.invalidateQueries({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

/**
 * Hook to delete an index from a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 */
export function useDeleteProjectTableIndex(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (indexKey: string) => {
      if (!projectId || !databaseId || !tableId || !indexKey) {
        throw new Error('Missing required parameters')
      }
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return await deleteProjectTableIndex(
        projectId,
        databaseId,
        tableId,
        indexKey,
      )
    },
    onSuccess: async () => {
      // Refetch indexes/tables list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['indexes', 'project', projectId, databaseId, tableId],
      })
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      await queryClient.refetchQueries({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

/**
 * Hook to update a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 */
export function useUpdateProjectTable(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: {
      name: string
      permissions: string[]
      rowSecurity: boolean
      enabled: boolean
    }) => {
      if (!projectId || !databaseId || !tableId) {
        throw new Error('Missing required parameters')
      }
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return await updateProjectTable(projectId, databaseId, tableId, data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      queryClient.invalidateQueries({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

/**
 * Hook to delete a table
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 */
export function useDeleteProjectTable(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async () => {
      if (!projectId || !databaseId || !tableId) {
        throw new Error('Missing required parameters')
      }
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return await deleteProjectTable(projectId, databaseId, tableId)
    },
    onSuccess: async () => {
      // Refetch tables/databases list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      await queryClient.refetchQueries({
        queryKey: ['databases', 'project', projectId],
      })
    },
  })
}

/**
 * Commit staged row cell edits atomically using a database transaction.
 */
type TableRowsListCache = {
  rows: unknown[]
  total: number
}

function applyEditsToApiRow(
  row: Record<string, unknown>,
  edits: PendingRowCellEdit[],
): Record<string, unknown> {
  const rowId = String(row.$id ?? '')
  const rowEdits = edits.filter((edit) => edit.rowId === rowId)
  if (rowEdits.length === 0) return row

  const next = { ...row }
  for (const edit of rowEdits) {
    const serialized = serializeRowDataForApi({ [edit.columnKey]: edit.value })
    next[edit.columnKey] = serialized[edit.columnKey]
  }
  return next
}

/**
 * Patch list-row caches with committed values before clearing pending edits,
 * so the grid does not flash back to stale data while refetching.
 */
export function applyCommittedEditsToRowsCache(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  edits: PendingRowCellEdit[],
) {
  if (edits.length === 0) return

  const editsByTable = new Map<string, PendingRowCellEdit[]>()
  for (const edit of edits) {
    const list = editsByTable.get(edit.tableId)
    if (list) list.push(edit)
    else editsByTable.set(edit.tableId, [edit])
  }

  for (const [tableId, tableEdits] of editsByTable) {
    queryClient.setQueriesData<TableRowsListCache>(
      {
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      },
      (old) => {
        if (!old?.rows?.length) return old
        const editedRowIds = new Set(tableEdits.map((edit) => edit.rowId))
        return {
          ...old,
          rows: old.rows.map((row) => {
            const record = row as Record<string, unknown>
            if (!editedRowIds.has(String(record.$id))) return row
            return applyEditsToApiRow(record, tableEdits)
          }),
        }
      },
    )
  }
}

type RowEditTransactionSdk = {
  createTransaction: (params?: { ttl?: number }) => Promise<{ $id: string }>
  createOperations?: (params: {
    transactionId: string
    operations?: object[]
  }) => Promise<unknown>
  updateTransaction: (params: {
    transactionId: string
    commit?: boolean
    rollback?: boolean
  }) => Promise<unknown>
}

type DocumentRowUpdateOperation = {
  databaseId: string
  collectionId: string
  documentId: string
  data: Record<string, unknown>
}

function getRowEditTransactionSdk(
  projectSdk: ReturnType<typeof sdk.forProject>,
  kind: DatabaseType,
): RowEditTransactionSdk {
  if (kind === DatabaseType.Documentsdb) return projectSdk.documentsDB
  if (kind === DatabaseType.Vectorsdb) return projectSdk.vectorsDB
  return projectSdk.tablesDB
}

async function stageRowEditTransactionOperations(
  projectSdk: ReturnType<typeof sdk.forProject>,
  kind: DatabaseType,
  transactionId: string,
  operations: object[],
) {
  if (kind === DatabaseType.Documentsdb) {
    for (const operation of operations as DocumentRowUpdateOperation[]) {
      await projectSdk.documentsDB.updateDocument({
        databaseId: operation.databaseId,
        collectionId: operation.collectionId,
        documentId: operation.documentId,
        data: operation.data,
        transactionId,
      })
    }
    return
  }

  const transactionSdk = getRowEditTransactionSdk(projectSdk, kind)
  if (typeof transactionSdk.createOperations !== 'function') {
    throw new Error('Bulk transaction staging is not available in this environment')
  }

  await transactionSdk.createOperations({
    transactionId,
    operations,
  })
}

export async function commitProjectTableRowEdits(
  projectId: string,
  edits: PendingRowCellEdit[],
) {
  if (!projectId) {
    throw new Error('Missing project ID')
  }
  if (edits.length === 0) {
    throw new Error('No edits to commit')
  }

  const projectSdk = sdk.forProject(projectId)
  const databaseId = edits[0].databaseId
  const kind = await resolveProjectDatabaseType(projectId, databaseId)
  const transactionSdk = getRowEditTransactionSdk(projectSdk, kind)

  if (typeof transactionSdk.createTransaction !== 'function') {
    throw new Error('Transactions are not available in this environment')
  }

  const operations = groupEditsIntoUpdateOperations(edits, kind)
  const tx = await transactionSdk.createTransaction()

  try {
    await stageRowEditTransactionOperations(
      projectSdk,
      kind,
      tx.$id,
      operations,
    )
    await transactionSdk.updateTransaction({
      transactionId: tx.$id,
      commit: true,
    })
  } catch (error) {
    try {
      await transactionSdk.updateTransaction({
        transactionId: tx.$id,
        rollback: true,
      })
    } catch {
      /* best-effort rollback */
    }
    throw error
  }

  const affectedTables = [...new Set(edits.map((edit) => edit.tableId))]

  return {
    transactionId: tx.$id,
    affectedTables,
    editCount: edits.length,
    rowCount: operations.length,
  }
}
