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
import { Query, ID, DatabaseType } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import type { Database, Collection } from '@/lib/utils/mock-data'
import { sdk } from '@/lib/appwrite/sdk'
import { getDedicatedDatabaseIdError, resolveDedicatedDatabaseId } from '@/lib/dedicated-database-id'
import { SERVERLESS_DATABASE_SPEC_ID } from '@/lib/database-specs'
import type { NativeDatabaseEngine } from '@/lib/databases/native-database-engines'
import { dedicatedEngineService } from '@/lib/databases/dedicated-engine'
import {
  databaseRouteKindFromApiType,
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

function buildProjectDatabaseDetail(db: Models.Database) {
  const dbRecord = db as unknown as Record<string, unknown>
  const backupPolicies =
    dbRecord.backupPolicies ||
    dbRecord.backups ||
    dbRecord.policies ||
    dbRecord.backup ||
    []
  const backupPoliciesArray = Array.isArray(backupPolicies)
    ? backupPolicies
    : backupPolicies
      ? [backupPolicies]
      : []
  const backupPolicyCount = backupPoliciesArray.length
  const hasBackupPolicy =
    backupPolicyCount > 0 ||
    dbRecord.backupEnabled === true ||
    dbRecord.backupPolicyEnabled === true
  const backupPolicy = backupPoliciesArray[0] || null

  return {
    $id: db.$id,
    name: db.name || 'Unnamed Database',
    tables: (dbRecord.collections as unknown[] | undefined)?.length || 0,
    rows: (dbRecord.documents as number | undefined) || 0,
    enabled: db.enabled !== false,
    createdAt: db.$createdAt || new Date().toISOString(),
    updatedAt: db.$updatedAt || db.$createdAt || new Date().toISOString(),
    hasBackupPolicy,
    backupPolicy,
    backupPolicyCount,
    databaseType: db.type,
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
        value: value.type,
        expiresAt: Date.now() + DEFAULT_STALE_TIME,
      })
    }
    return value
  } finally {
    databaseModelInflight.delete(key)
  }
}

function databaseTypeRank(type: DatabaseType | undefined): number {
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
  sourceType: DatabaseType,
): Models.Database {
  return {
    ...db,
    // The probing API is authoritative; `type` on the payload is unreliable.
    type: sourceType,
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
  return attributes.map((raw) => {
    const a = raw as Record<string, unknown>
    const key = String(a.key ?? '')
    const type = String(a.type ?? 'string')
    return {
      ...a,
      key,
      type,
      status: (a.status as string) || 'available',
    }
  })
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
    projectSdk.documentsDB.list({ queries: mergeQueries }),
    projectSdk.vectorsDB.list({ queries: mergeQueries }),
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

  let response: Models.DatabaseList
  if (backend === DatabaseType.Documentsdb) {
    response = await projectSdk.documentsDB.list({ queries })
  } else if (backend === DatabaseType.Vectorsdb) {
    response = await projectSdk.vectorsDB.list({ queries })
  } else {
    response = await projectSdk.tablesDB.list({ queries })
  }

  const databases = (response.databases ?? []).map((db) =>
    normalizeProductDatabase(db, backend),
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
    projectSdk.documentsDB.list({
      queries: [idQuery, Query.limit(validIds.length)],
    }),
    projectSdk.vectorsDB.list({
      queries: [idQuery, Query.limit(validIds.length)],
    }),
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
) {
  if (!projectId || !databaseId) {
    return null
  }

  try {
    const db = await getDatabaseModel(projectId, databaseId)

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
 * DocumentsDB and VectorsDB always provision dedicated compute first; TablesDB uses
 * dedicated compute when `specification` is set and not serverless (`shared` slug).
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
  try {
    if (backend === DatabaseType.Documentsdb) {
      return await projectSdk.documentsDB.get({ databaseId })
    }
    if (backend === DatabaseType.Vectorsdb) {
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
    queryFn: () => resolveProductRouteKindForDatabase(projectId!, databaseId!),
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

function isDedicatedDatabaseNotFoundError(error: unknown): boolean {
  const message =
    error instanceof Error
      ? error.message
      : typeof error === 'string'
        ? error
        : ''
  return message.toLowerCase().includes('dedicated database') &&
    message.toLowerCase().includes('could not be found')
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
    dedicatedDatabaseId?: string
  },
) {
  const payload = {
    databaseId: params.databaseId,
    name: params.name,
    ...(params.dedicatedDatabaseId
      ? { dedicatedDatabaseId: params.dedicatedDatabaseId }
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

async function createProductDatabaseWithRetry(
  projectSdk: ReturnType<typeof sdk.forProject>,
  backend: DatabaseType,
  params: {
    databaseId: string
    name: string
    dedicatedDatabaseId: string
  },
  maxAttempts = 20,
): Promise<Models.Database> {
  let lastError: unknown
  let intervalMs = 1200
  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    try {
      return await createProductDatabase(projectSdk, backend, params)
    } catch (error) {
      lastError = error
      if (isDatabaseAlreadyExistsError(error)) {
        const existing = await waitForProductDatabaseAfterExistsConflict(
          projectSdk,
          backend,
          params.databaseId,
        )
        if (existing) return existing
      }
      if (!isDedicatedDatabaseNotFoundError(error) || attempt === maxAttempts - 1) {
        throw error
      }
      await sleep(intervalMs)
      intervalMs = Math.min(Math.round(intervalMs * 1.25), 4000)
    }
  }
  throw lastError instanceof Error ? lastError : new Error('Failed to create database')
}

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

  const sharedPayload = {
    databaseId,
    name,
    specification,
    replicas: haReplicaCount,
    backupEnabled: pitrEnabled,
    backupPitr: pitrEnabled,
  }

  const engine = dedicatedComputeEngineForProductBackend(backend)
  const createParams =
    engine != null
      ? {
          ...sharedPayload,
          engine,
          ...(engine === 'mongodb' ? { backend: 'edge' as const } : {}),
          api: computeApiForDatabaseType(backend),
        }
      : {
          ...sharedPayload,
          api: computeApiForDatabaseType(backend),
        }

  const engineService = dedicatedEngineService(projectSdk, engine ?? undefined)
  try {
    return await engineService.create(createParams)
  } catch (error) {
    if (!isDatabaseAlreadyExistsError(error)) {
      throw error
    }
    return await engineService.get({ databaseId })
  }
}

/**
 * Create a new database in a project.
 * DocumentsDB and VectorsDB provision dedicated compute first, then register the
 * product database with its own ID and `dedicatedDatabaseId` pointing at compute.
 * TablesDB uses the serverless pool unless a dedicated specification is provided.
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

  if (useDedicated) {
    const specification = await resolveDedicatedSpecification(
      projectId,
      region,
      options?.specification,
      dedicatedComputeEngineForProductBackend(backend),
    )
    const haReplicaCount = Math.max(0, options?.haReplicaCount ?? 0)
    const pitrEnabled = options?.pitrEnabled === true
    const computeDatabaseId = ID.unique()

    const dedicated = await provisionDedicatedCompute(projectSdk, {
      databaseId: computeDatabaseId,
      name,
      specification,
      backend,
      haReplicaCount,
      pitrEnabled,
    })
    const dedicatedComputeId = dedicated.$id || computeDatabaseId

    const created = await createProductDatabaseWithRetry(projectSdk, backend, {
      databaseId: productDatabaseId,
      name,
      dedicatedDatabaseId: dedicatedComputeId,
    })
    seedDatabaseModelCache(projectId, productDatabaseId, created, backend)
    return normalizeProductDatabase(created, backend)
  }

  const created = await createProductDatabase(projectSdk, backend, {
    databaseId: productDatabaseId,
    name,
  })
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
 * Create a native Postgres or MySQL database via the Compute service.
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

  return await dedicatedEngineService(projectSdk, data.engine).create({
    databaseId,
    name: data.name.trim(),
    engine: data.engine,
    specification: data.specification.trim(),
    replicas: haReplicaCount,
    backupEnabled: pitrEnabled,
    backupPitr: pitrEnabled,
  })
}

export async function fetchDatabaseSpecifications(projectId: string) {
  if (!projectId) {
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
    enabled: !!projectId,
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

export async function fetchProjectDedicatedDatabases(projectId: string) {
  if (!projectId) {
    return { databases: [] as Models.DedicatedDatabase[], total: 0 }
  }

  // The SDK split dedicated databases into per-engine services, so list each
  // engine and merge.
  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(MERGED_DATABASE_LIST_LIMIT),
  ]
  const results = await Promise.allSettled([
    projectSdk.postgresql.list({ queries }),
    projectSdk.mysql.list({ queries }),
    projectSdk.mongo.list({ queries }),
  ])

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

export function dedicatedDatabasesQueryOptions(
  projectId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['dedicated-databases', 'project', projectId],
    queryFn: () => fetchProjectDedicatedDatabases(projectId!),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
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
        : 384
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

  const kind = await resolveProjectDatabaseType(projectId, databaseId)

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
      return { tables: response.collections ?? [] }
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
      return { tables: response.collections ?? [] }
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
  search?: string,
  order: 'asc' | 'desc' = 'desc',
  sortBy: RowsSortBy = '$createdAt',
  filterQueries?: string[],
  listSelectAttrKeys?: string[] | null,
) {
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
    if (typeof projectSdk.tablesDB.listRows === 'function') {
      const listRowsParams: Record<string, unknown> = {
        databaseId,
        tableId,
        queries,
      }
      if (search?.trim()) {
        listRowsParams.search = search.trim()
      }
      response = (await projectSdk.tablesDB.listRows(
        listRowsParams as never,
      )) as typeof response
    } else {
      response = { rows: [], total: 0 }
    }
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
    if (typeof projectSdk.tablesDB.getRow === 'function') {
      return await projectSdk.tablesDB.getRow({
        databaseId,
        tableId,
        rowId,
      })
    }
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
    } else if (typeof projectSdk.tablesDB.getTable === 'function') {
      response = (await projectSdk.tablesDB.getTable({
        databaseId,
        tableId,
      })) as unknown as Record<string, unknown>
    }

    if (!response) {
      return null
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

  if (typeof projectSdk.tablesDB.deleteRow === 'function') {
    await projectSdk.tablesDB.deleteRow({
      databaseId,
      tableId,
      rowId,
    })
  } else {
    throw new Error('Delete row method not available')
  }
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
    const createParams: {
      databaseId: string
      collectionId: string
      documentId: string
      data: Record<string, unknown>
      permissions?: string[]
    } = {
      databaseId,
      collectionId: tableId,
      documentId: id,
      data: payload,
    }
    if (permissions && permissions.length > 0) {
      createParams.permissions = permissions
    }
    const created = await projectSdk.documentsDB.createDocument(
      createParams as never,
    )
    return flattenDocumentForTableRow(created as Record<string, unknown>)
  }

  if (kind === DatabaseType.Vectorsdb) {
    payload = await ensureDocumentOrVectorCreateDataPopulated(
      projectId,
      databaseId,
      tableId,
      payload,
    )
    const createParams: {
      databaseId: string
      collectionId: string
      documentId: string
      data: Record<string, unknown>
      permissions?: string[]
    } = {
      databaseId,
      collectionId: tableId,
      documentId: id,
      data: payload,
    }
    if (permissions && permissions.length > 0) {
      createParams.permissions = permissions
    }
    const created = await projectSdk.vectorsDB.createDocument(
      createParams as never,
    )
    return flattenDocumentForTableRow(created as Record<string, unknown>)
  }

  if (typeof projectSdk.tablesDB.createRow === 'function') {
    const createParams: Record<string, unknown> = {
      databaseId,
      tableId,
      rowId: id,
      data: { ...payload },
    }

    if (permissions && permissions.length > 0) {
      createParams.permissions = permissions
    }

    return await projectSdk.tablesDB.createRow(createParams as never)
  }

  throw new Error('Create row method not available')
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
    const updateParams: {
      databaseId: string
      collectionId: string
      documentId: string
      data: Record<string, unknown>
      permissions?: string[]
    } = {
      databaseId,
      collectionId: tableId,
      documentId: rowId,
      data: payload,
    }
    if (permissions !== undefined) {
      updateParams.permissions = permissions
    }
    const updated = await projectSdk.documentsDB.updateDocument(
      updateParams as never,
    )
    return flattenDocumentForTableRow(updated as Record<string, unknown>)
  }

  if (kind === DatabaseType.Vectorsdb) {
    const updateParams: {
      databaseId: string
      collectionId: string
      documentId: string
      data: Record<string, unknown>
      permissions?: string[]
    } = {
      databaseId,
      collectionId: tableId,
      documentId: rowId,
      data: payload,
    }
    if (permissions !== undefined) {
      updateParams.permissions = permissions
    }
    const updated = await projectSdk.vectorsDB.updateDocument(
      updateParams as never,
    )
    return flattenDocumentForTableRow(updated as Record<string, unknown>)
  }

  if (typeof projectSdk.tablesDB.updateRow === 'function') {
    const updateParams: Record<string, unknown> = {
      databaseId,
      tableId,
      rowId,
      data: payload,
    }

    if (permissions !== undefined) {
      updateParams.permissions = permissions
    }

    return await projectSdk.tablesDB.updateRow(updateParams as never)
  }

  throw new Error('Update row method not available')
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
  const tablesBulk = projectSdk.tablesDB as unknown as {
    createRows?: (p: {
      databaseId: string
      tableId: string
      rows: { rowId: string; data: Record<string, unknown> }[]
    }) => Promise<unknown>
  }

  if (!hasRelationshipColumns && typeof tablesBulk.createRows === 'function') {
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
          rowId,
          data: rowData,
        }
      })

      await tablesBulk.createRows({
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
  const {
    key,
    type,
    required = false,
    array = false,
    xdefault,
    size,
    min,
    max,
    elements,
  } = data
  // TablesDB create methods only add encrypt to payload when typeof encrypt !== 'undefined'. Always pass explicit boolean for text types.
  const encrypt = data.encrypt === true
  const colKey = typeof key === 'string' ? key : String(key ?? '')
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
        size: size ?? 255,
        required,
        xdefault,
        array,
        encrypt,
      } as never)
    case 'text':
      return await projectSdk.tablesDB.createTextColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault,
        array,
        encrypt,
      } as never)
    case 'mediumtext':
      return await projectSdk.tablesDB.createMediumtextColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault,
        array,
        encrypt,
      } as never)
    case 'longtext':
      return await projectSdk.tablesDB.createLongtextColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault,
        array,
        encrypt,
      } as never)
    case 'string':
      return await projectSdk.tablesDB.createStringColumn({
        databaseId,
        tableId,
        key: colKey,
        size: size || 255,
        required,
        xdefault,
        array,
        encrypt,
      } as never)
    case 'integer':
      return await projectSdk.tablesDB.createIntegerColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        min,
        max,
        xdefault,
        array,
      } as never)
    case 'bigint':
      return await projectSdk.tablesDB.createBigIntColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        min,
        max,
        xdefault,
        array,
      } as never)
    case 'double':
    case 'float':
      return await projectSdk.tablesDB.createFloatColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        min,
        max,
        xdefault,
        array,
      } as never)
    case 'boolean':
      return await projectSdk.tablesDB.createBooleanColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault,
        array,
      } as never)
    case 'datetime':
      return await projectSdk.tablesDB.createDatetimeColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault,
        array,
      } as never)
    case 'email':
      return await projectSdk.tablesDB.createEmailColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault,
        array,
      } as never)
    case 'ip':
      return await projectSdk.tablesDB.createIpColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault,
        array,
      } as never)
    case 'url':
      return await projectSdk.tablesDB.createUrlColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault,
        array,
      } as never)
    case 'enum':
      return await projectSdk.tablesDB.createEnumColumn({
        databaseId,
        tableId,
        key: colKey,
        elements: (Array.isArray(elements) ? elements : []) as string[],
        required,
        xdefault,
        array,
      } as never)
    case 'relationship':
      return await projectSdk.tablesDB.createRelationshipColumn({
        databaseId,
        tableId,
        relatedTableId: data.relatedTableId as string,
        type: data.relationshipType as never,
        twoWay: data.twoWay as boolean,
        key: colKey,
        twoWayKey: data.twoWayKey as string,
        onDelete: data.onDelete as never,
      } as never)
    case 'point':
      return await projectSdk.tablesDB.createPointColumn({
        databaseId,
        tableId,
        key: colKey,
        required: required as boolean,
        xdefault: xdefault as number[] | undefined,
      })
    case 'linestring':
      return await projectSdk.tablesDB.createLineColumn({
        databaseId,
        tableId,
        key: colKey,
        required: required as boolean,
        xdefault: xdefault as number[][] | undefined,
      })
    case 'polygon':
      return await projectSdk.tablesDB.createPolygonColumn({
        databaseId,
        tableId,
        key: colKey,
        required: required as boolean,
        xdefault: xdefault as number[][] | undefined,
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
  const {
    type,
    required = false,
    xdefault,
    size,
    min,
    max,
    elements,
    newKey,
  } = data
  // Explicit boolean so API receives true/false, not undefined
  const encrypt = typeof data.encrypt === 'boolean' ? data.encrypt : false

  // Call the appropriate update method based on column type
  switch (type) {
    case 'varchar':
      return await projectSdk.tablesDB.updateVarcharColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        size,
        newKey,
        encrypt,
      } as never)
    case 'text':
      return await projectSdk.tablesDB.updateTextColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
        encrypt,
      } as never)
    case 'mediumtext':
      return await projectSdk.tablesDB.updateMediumtextColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
        encrypt,
      } as never)
    case 'longtext':
      return await projectSdk.tablesDB.updateLongtextColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
        encrypt,
      } as never)
    case 'string':
      return await projectSdk.tablesDB.updateStringColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        size,
        newKey,
        encrypt,
      } as never)
    case 'integer':
      return await projectSdk.tablesDB.updateIntegerColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        min,
        max,
        xdefault,
        newKey,
      } as never)
    case 'bigint':
      return await projectSdk.tablesDB.updateBigIntColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        min,
        max,
        xdefault,
        newKey,
      } as never)
    case 'double':
    case 'float':
      return await projectSdk.tablesDB.updateFloatColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        min,
        max,
        xdefault,
        newKey,
      } as never)
    case 'boolean':
      return await projectSdk.tablesDB.updateBooleanColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      } as never)
    case 'datetime':
      return await projectSdk.tablesDB.updateDatetimeColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      } as never)
    case 'email':
      return await projectSdk.tablesDB.updateEmailColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      } as never)
    case 'ip':
      return await projectSdk.tablesDB.updateIpColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      } as never)
    case 'url':
      return await projectSdk.tablesDB.updateUrlColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      } as never)
    case 'enum':
      return await projectSdk.tablesDB.updateEnumColumn({
        databaseId,
        tableId,
        key: columnKey,
        elements: (Array.isArray(elements) ? elements : []) as string[],
        required,
        xdefault,
        newKey,
      } as never)
    case 'relationship':
      return await projectSdk.tablesDB.updateRelationshipColumn({
        databaseId,
        tableId,
        key: columnKey,
        onDelete: (columnData as Record<string, unknown>).onDelete,
        newKey,
      } as never)
    case 'point':
      return await projectSdk.tablesDB.updatePointColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      } as never)
    case 'linestring':
      return await projectSdk.tablesDB.updateLineColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      } as never)
    case 'polygon':
      return await projectSdk.tablesDB.updatePolygonColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      } as never)
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
  const tdb = projectSdk.tablesDB as unknown as {
    deleteAttribute?: (p: {
      databaseId: string
      tableId: string
      key: string
    }) => Promise<unknown>
    deleteColumn?: (p: {
      databaseId: string
      tableId: string
      key: string
    }) => Promise<unknown>
  }

  if (typeof tdb.deleteAttribute === 'function') {
    return await tdb.deleteAttribute({
      databaseId,
      tableId,
      key: columnKey,
    })
  }
  if (typeof tdb.deleteColumn === 'function') {
    return await tdb.deleteColumn({
      databaseId,
      tableId,
      key: columnKey,
    })
  } else {
    throw new Error('Delete column method not available')
  }
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

  if (kind === DatabaseType.Documentsdb) {
    return await projectSdk.documentsDB.createIndex({
      databaseId,
      collectionId: tableId,
      key,
      type: type as never,
      attributes: columns,
      orders: orders as never,
      lengths,
    })
  }

  if (kind === DatabaseType.Vectorsdb) {
    return await projectSdk.vectorsDB.createIndex({
      databaseId,
      collectionId: tableId,
      key,
      type: type as never,
      attributes: columns,
      orders: orders as never,
      lengths,
    })
  }

  const tdbIdx = projectSdk.tablesDB as unknown as {
    createIndex?: (p: Record<string, unknown>) => Promise<unknown>
  }
  if (typeof tdbIdx.createIndex === 'function') {
    return await tdbIdx.createIndex({
      databaseId,
      tableId,
      ...(indexData as object),
    })
  }

  throw new Error('Create index method not available')
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

  const tdbDelIdx = projectSdk.tablesDB as unknown as {
    deleteIndex?: (p: {
      databaseId: string
      tableId: string
      key: string
    }) => Promise<unknown>
  }
  if (typeof tdbDelIdx.deleteIndex === 'function') {
    return await tdbDelIdx.deleteIndex({
      databaseId,
      tableId,
      key: indexKey,
    })
  }

  throw new Error('Delete index method not available')
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

  const tdbUp = projectSdk.tablesDB as unknown as {
    updateTable?: (p: Record<string, unknown>) => Promise<unknown>
  }
  if (typeof tdbUp.updateTable === 'function') {
    return await tdbUp.updateTable({
      databaseId,
      tableId,
      ...data,
    })
  }

  throw new Error('Update table method not available')
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

  const tdbDel = projectSdk.tablesDB as unknown as {
    deleteTable?: (p: { databaseId: string; tableId: string }) => Promise<unknown>
  }
  if (typeof tdbDel.deleteTable === 'function') {
    return await tdbDel.deleteTable({
      databaseId,
      tableId,
    })
  }

  throw new Error('Delete table method not available')
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

function mapProjectDatabaseListItems(
  databasesData: { databases?: Models.Database[] } | undefined,
  limit: number,
) {
  const databases = (databasesData?.databases ?? []).map((db: unknown) => {
    const d = db as Record<string, unknown>
    const tables = (d.collections as unknown[] | undefined)?.length || 0
    const rows = (d.documents as number | undefined) || 0
    const backupPolicies =
      d.backupPolicies || d.backups || d.policies || d.backup || []
    const backupPoliciesArray = Array.isArray(backupPolicies)
      ? backupPolicies
      : backupPolicies
        ? [backupPolicies]
        : []
    const backupPolicyCount = backupPoliciesArray.length
    const hasBackupPolicy =
      backupPolicyCount > 0 ||
      d.backupEnabled === true ||
      d.backupPolicyEnabled === true
    const backupPolicy = backupPoliciesArray[0] || null

    return {
      $id: d.$id as string,
      name: (d.name as string) || 'Unnamed Database',
      tables,
      rows,
      enabled: d.enabled !== false,
      createdAt: (d.$createdAt as string) || new Date().toISOString(),
      updatedAt:
        (d.$updatedAt as string) ||
        (d.$createdAt as string) ||
        new Date().toISOString(),
      hasBackupPolicy,
      backupPolicy,
      backupPolicyCount,
      databaseType: (d as unknown as Models.Database).type,
    } as Database & {
      enabled: boolean
      createdAt: string
      updatedAt: string
      hasBackupPolicy: boolean
      backupPolicy: unknown
      backupPolicyCount: number
      databaseType?: DatabaseType
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
) {
  return queryOptions({
    queryKey: ['database', 'project', projectId, databaseId],
    queryFn: () => fetchProjectDatabase(projectId!, databaseId!),
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
 * Hook to fetch a single database by ID
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @returns Single database with loading state
 */
export function useProjectDatabase(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const {
    data: databaseData,
    isLoading,
    isPending,
    error,
    refetch,
  } = useQuery(databaseQueryOptions(projectId, databaseId))

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
