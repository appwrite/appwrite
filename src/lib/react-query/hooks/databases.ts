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
} from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query, ID, DatabaseType } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import type { Database, Collection } from '@/lib/utils/mock-data'
import { sdk } from '@/lib/appwrite/sdk'
import {
  DEFAULT_STALE_TIME,
  DEFAULT_PAGE_SIZE,
  COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
} from './constants'

const MERGED_DATABASE_LIST_LIMIT = 500

/** Resolve a database from whichever product API owns it (Tables, Documents, or Vectors). */
export async function getDatabaseModel(
  projectId: string,
  databaseId: string,
): Promise<Models.Database | null> {
  if (!projectId || !databaseId) return null
  const projectSdk = sdk.forProject(projectId)
  for (const tryGet of [
    () => projectSdk.tablesDB.get({ databaseId }),
    () => projectSdk.documentsDB.get({ databaseId }),
    () => projectSdk.vectorsDB.get({ databaseId }),
  ]) {
    try {
      const d = await tryGet()
      if (d?.$id) return d
    } catch {
      /* try next backend */
    }
  }
  return null
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
  const mergeQueries = [
    ...(filterQueries ?? []),
    Query.orderDesc('$createdAt'),
    Query.limit(MERGED_DATABASE_LIST_LIMIT),
  ]
  const searchArg = search?.trim() || undefined

  const settled = await Promise.allSettled([
    projectSdk.tablesDB.list({ queries: mergeQueries, search: searchArg }),
    projectSdk.documentsDB.list({ queries: mergeQueries, search: searchArg }),
    projectSdk.vectorsDB.list({ queries: mergeQueries, search: searchArg }),
  ])

  const merged: Models.Database[] = []
  for (const s of settled) {
    if (s.status === 'fulfilled' && s.value.databases?.length) {
      merged.push(...s.value.databases)
    }
  }

  const byId = new Map<string, Models.Database>()
  for (const d of merged) {
    if (d?.$id && !byId.has(d.$id)) {
      byId.set(d.$id, d)
    }
  }

  const sorted = [...byId.values()].sort(
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
    } as Database & {
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
 * Create a new database in a project.
 * Uses TablesDB.create. When databaseId is omitted or empty, generates one via ID.unique().
 *
 * @param projectId - The project ID
 * @param data - { databaseId?: string; name: string }
 * @returns The created database (Models.Database)
 */
export async function createProjectDatabase(
  projectId: string,
  data: { databaseId?: string | null; name: string },
  backend: DatabaseType = DatabaseType.Tablesdb,
) {
  if (!projectId) {
    throw new Error('Project ID is required')
  }
  const projectSdk = sdk.forProject(projectId)
  const databaseId =
    data.databaseId && data.databaseId.trim() !== ''
      ? data.databaseId.trim()
      : ID.unique()
  const name = data.name.trim()
  if (backend === DatabaseType.Documentsdb) {
    return await projectSdk.documentsDB.create({ databaseId, name })
  }
  if (backend === DatabaseType.Vectorsdb) {
    return await projectSdk.vectorsDB.create({ databaseId, name })
  }
  return await projectSdk.tablesDB.create({ databaseId, name })
}

/**
 * Create a new table (collection) in a database
 *
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param data - The table data (tableId and name)
 * @returns Created table
 */
export async function createProjectTable(
  projectId: string,
  databaseId: string,
  data: { tableId?: string | null; name: string; dimension?: number },
) {
  if (!projectId || !databaseId) {
    throw new Error('Project ID and Database ID are required')
  }
  const projectSdk = sdk.forProject(projectId)
  const tableId =
    data.tableId && data.tableId.trim() !== ''
      ? data.tableId.trim()
      : ID.unique()

  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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
    if (type === 'integer' || type === 'double') {
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

  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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

  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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
) {
  if (!projectId || !databaseId || !tableId) {
    return { rows: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    ...(filterQueries ?? []),
    order === 'asc' ? Query.orderAsc(sortBy) : Query.orderDesc(sortBy),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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
  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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

  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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

  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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
  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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
  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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
  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb
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
  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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
        required,
        xdefault,
      })
    case 'linestring':
      return await projectSdk.tablesDB.createLineColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault,
      })
    case 'polygon':
      return await projectSdk.tablesDB.createPolygonColumn({
        databaseId,
        tableId,
        key: colKey,
        required,
        xdefault,
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
  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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
  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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
  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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
  const dm = await getDatabaseModel(projectId, databaseId)
  const kind = dm?.type ?? DatabaseType.Tablesdb

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
) {
  const normalizedSearch = search?.trim() || undefined

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

  // Map databases to our Database type
  const databases = useMemo(() => {
    if (!databasesData?.databases) return []

    return databasesData.databases.map((db: unknown) => {
      const d = db as Record<string, unknown>
      // Get table count and row count if available
      // These might need to be fetched separately or calculated
      const tables = (d.collections as unknown[] | undefined)?.length || 0
      const rows = (d.documents as number | undefined) || 0

      // Check for backup policies - check multiple possible field names
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
        enabled: d.enabled !== false, // Default to true if not specified
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
  }, [databasesData])

  const totalPages = useMemo(() => {
    if (!databasesData?.total) return 0
    return Math.ceil(databasesData.total / limit)
  }, [databasesData?.total, limit])

  return {
    databases,
    total: databasesData?.total || 0,
    totalPages,
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
    onSuccess: async () => {
      // Refetch columns/tables list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['columns', 'project', projectId, databaseId, tableId],
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
