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
} from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query, ID } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import type { Database, Collection } from '@/lib/utils/mock-data'
import { sdk } from '@/lib/appwrite/sdk'
import {
  DEFAULT_STALE_TIME,
  DEFAULT_PAGE_SIZE,
  COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
} from './constants'

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
  const queries = [
    ...(filterQueries ?? []),
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  // Use TablesDB API to list databases
  let response: Models.DatabaseList
  try {
    response = await projectSdk.tablesDB.list({
      queries,
      search: search?.trim() || undefined,
    })
  } catch {
    response = { databases: [], total: 0 }
  }

  return {
    databases: response.databases ?? [],
    total: response.total ?? 0,
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

  const projectSdk = sdk.forProject(projectId)
  try {
    const db = await projectSdk.tablesDB.get({ databaseId })

    if (!db) {
      return null
    }

    // Map to our Database type
    const backupPolicies =
      db.backupPolicies || db.backups || db.policies || db.backup || []
    const backupPoliciesArray = Array.isArray(backupPolicies)
      ? backupPolicies
      : backupPolicies
        ? [backupPolicies]
        : []
    const backupPolicyCount = backupPoliciesArray.length
    const hasBackupPolicy =
      backupPolicyCount > 0 ||
      db.backupEnabled === true ||
      db.backupPolicyEnabled === true
    const backupPolicy = backupPoliciesArray[0] || null

    return {
      $id: db.$id,
      name: db.name || 'Unnamed Database',
      tables: db.collections?.length || 0,
      rows: db.documents || 0,
      enabled: db.enabled !== false, // Default to true if not specified
      createdAt: db.$createdAt || new Date().toISOString(),
      updatedAt: db.$updatedAt || db.$createdAt || new Date().toISOString(),
      hasBackupPolicy,
      backupPolicy,
      backupPolicyCount,
    } as Database & {
      enabled: boolean
      createdAt: string
      updatedAt: string
      hasBackupPolicy: boolean
      backupPolicy: Record<string, unknown> | null
      backupPolicyCount: number
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
) {
  if (!projectId) {
    throw new Error('Project ID is required')
  }
  const projectSdk = sdk.forProject(projectId)
  const databaseId =
    data.databaseId && data.databaseId.trim() !== ''
      ? data.databaseId.trim()
      : ID.unique()
  return await projectSdk.tablesDB.create({
    databaseId,
    name: data.name.trim(),
  })
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
  data: { tableId?: string | null; name: string },
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

  // Use TablesDB API to list tables (collections)
  let response: Models.TableList
  try {
    response = await projectSdk.tablesDB.listTables({
      databaseId,
      queries,
      search: search?.trim() || undefined,
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
    Query.limit(1000), // Fetch all tables (high limit)
  ]

  // Use TablesDB API to list tables (collections)
  let response: Models.TableList
  try {
    response = await projectSdk.tablesDB.listTables({
      databaseId,
      queries,
    })
  } catch {
    response = { tables: [] }
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

  // Use TablesDB API to list rows
  let response: unknown
  try {
    if (typeof projectSdk.tablesDB.listRows === 'function') {
      // Note: search might need to be passed differently depending on SDK version
      const listRowsParams: unknown = {
        databaseId,
        tableId,
        queries,
      }
      // Try to add search if the method supports it
      if (search?.trim()) {
        // Some SDK versions might support search in queries or as a separate param
        listRowsParams.search = search.trim()
      }
      response = await projectSdk.tablesDB.listRows(listRowsParams)
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
  try {
    if (typeof projectSdk.tablesDB.getRow === 'function') {
      return await projectSdk.tablesDB.getRow({
        databaseId,
        tableId,
        rowId,
      })
    }
  } catch {
    // Row may not exist or no permission
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
  try {
    let response: unknown
    if (typeof projectSdk.tablesDB.getTable === 'function') {
      response = await projectSdk.tablesDB.getTable({ databaseId, tableId })
    } else if (
      typeof (projectSdk.tablesDB as unknown).getCollection === 'function'
    ) {
      response = await (projectSdk.tablesDB as unknown).getCollection({
        databaseId,
        tableId,
      })
    } else {
      return null
    }

    if (!response) {
      return null
    }

    return {
      $id: response.$id,
      name: response.name || 'Unnamed Table',
      databaseId: databaseId,
      enabled: response.enabled !== false, // Default to true if not specified
      rowSecurity: response.rowSecurity === true,
      $permissions: response.$permissions || [],
      $createdAt: response.$createdAt || new Date().toISOString(),
      $updatedAt:
        response.$updatedAt || response.$createdAt || new Date().toISOString(),
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

  if (typeof projectSdk.tablesDB.createRow === 'function') {
    const { ID } = await import('@appwrite.io/console')
    const createParams: unknown = {
      databaseId,
      tableId,
      rowId: rowId || data.$id || ID.unique(),
      data: { ...data },
    }

    // Remove $id from data if it exists (it's passed as rowId)
    if (createParams.data.$id) {
      delete createParams.data.$id
    }

    // Add permissions if provided
    if (permissions && permissions.length > 0) {
      createParams.permissions = permissions
    }

    return await projectSdk.tablesDB.createRow(createParams)
  } else {
    throw new Error('Create row method not available')
  }
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

  if (typeof projectSdk.tablesDB.updateRow === 'function') {
    const updateParams: unknown = {
      databaseId,
      tableId,
      rowId,
      data: { ...data },
    }

    // Remove $id from data if it exists (it's passed as rowId)
    if (updateParams.data.$id) {
      delete updateParams.data.$id
    }

    // Add permissions if provided (including empty array to clear permissions)
    if (permissions !== undefined) {
      updateParams.permissions = permissions
    }

    return await projectSdk.tablesDB.updateRow(updateParams)
  } else {
    throw new Error('Update row method not available')
  }
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
  if (
    !hasRelationshipColumns &&
    typeof (projectSdk.tablesDB as unknown).createRows === 'function'
  ) {
    try {
      const { ID } = await import('@appwrite.io/console')
      const rowsToInsert = rows.map((row) => {
        const rowData = { ...row }
        const rowId = row.$id || ID.unique()
        if (rowData.$id) {
          delete rowData.$id
        }
        return {
          rowId,
          data: rowData,
        }
      })

      await (projectSdk.tablesDB as unknown).createRows({
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
          await createProjectTableRow(
            projectId,
            databaseId,
            tableId,
            row,
            row.$id,
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

  // Call the appropriate method based on column type (TablesDB: createXColumn with databaseId, tableId, key, ...)
  switch (type) {
    case 'varchar':
      return await projectSdk.tablesDB.createVarcharColumn({
        databaseId,
        tableId,
        key,
        size: size ?? 255,
        required,
        xdefault,
        array,
        encrypt,
      })
    case 'text':
      return await projectSdk.tablesDB.createTextColumn({
        databaseId,
        tableId,
        key,
        required,
        xdefault,
        array,
        encrypt,
      })
    case 'mediumtext':
      return await projectSdk.tablesDB.createMediumtextColumn({
        databaseId,
        tableId,
        key,
        required,
        xdefault,
        array,
        encrypt,
      })
    case 'longtext':
      return await projectSdk.tablesDB.createLongtextColumn({
        databaseId,
        tableId,
        key,
        required,
        xdefault,
        array,
        encrypt,
      })
    case 'string':
      return await projectSdk.tablesDB.createStringColumn({
        databaseId,
        tableId,
        key,
        size: size || 255,
        required,
        xdefault,
        array,
        encrypt,
      })
    case 'integer':
      return await projectSdk.tablesDB.createIntegerColumn({
        databaseId,
        tableId,
        key,
        required,
        min,
        max,
        xdefault,
        array,
      })
    case 'double':
    case 'float':
      return await projectSdk.tablesDB.createFloatColumn({
        databaseId,
        tableId,
        key,
        required,
        min,
        max,
        xdefault,
        array,
      })
    case 'boolean':
      return await projectSdk.tablesDB.createBooleanColumn({
        databaseId,
        tableId,
        key,
        required,
        xdefault,
        array,
      })
    case 'datetime':
      return await projectSdk.tablesDB.createDatetimeColumn({
        databaseId,
        tableId,
        key,
        required,
        xdefault,
        array,
      })
    case 'email':
      return await projectSdk.tablesDB.createEmailColumn({
        databaseId,
        tableId,
        key,
        required,
        xdefault,
        array,
      })
    case 'ip':
      return await projectSdk.tablesDB.createIpColumn({
        databaseId,
        tableId,
        key,
        required,
        xdefault,
        array,
      })
    case 'url':
      return await projectSdk.tablesDB.createUrlColumn({
        databaseId,
        tableId,
        key,
        required,
        xdefault,
        array,
      })
    case 'enum':
      return await projectSdk.tablesDB.createEnumColumn({
        databaseId,
        tableId,
        key,
        elements: elements || [],
        required,
        xdefault,
        array,
      })
    case 'relationship':
      return await projectSdk.tablesDB.createRelationshipColumn({
        databaseId,
        tableId,
        ...columnData,
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
      })
    case 'text':
      return await projectSdk.tablesDB.updateTextColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
        encrypt,
      })
    case 'mediumtext':
      return await projectSdk.tablesDB.updateMediumtextColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
        encrypt,
      })
    case 'longtext':
      return await projectSdk.tablesDB.updateLongtextColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
        encrypt,
      })
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
      })
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
        xdefault,
        newKey,
      })
    case 'boolean':
      return await projectSdk.tablesDB.updateBooleanColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      })
    case 'datetime':
      return await projectSdk.tablesDB.updateDatetimeColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      })
    case 'email':
      return await projectSdk.tablesDB.updateEmailColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      })
    case 'ip':
      return await projectSdk.tablesDB.updateIpColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      })
    case 'url':
      return await projectSdk.tablesDB.updateUrlColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      })
    case 'enum':
      return await projectSdk.tablesDB.updateEnumColumn({
        databaseId,
        tableId,
        key: columnKey,
        elements: elements || [],
        required,
        xdefault,
        newKey,
      })
    case 'relationship':
      return await projectSdk.tablesDB.updateRelationshipColumn({
        databaseId,
        tableId,
        key: columnKey,
        onDelete: columnData.onDelete,
        newKey,
      })
    case 'point':
      return await projectSdk.tablesDB.updatePointColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      })
    case 'linestring':
      return await projectSdk.tablesDB.updateLineColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
        newKey,
      })
    case 'polygon':
      return await projectSdk.tablesDB.updatePolygonColumn({
        databaseId,
        tableId,
        key: columnKey,
        required,
        xdefault,
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

  if (typeof (projectSdk.tablesDB as unknown).deleteAttribute === 'function') {
    return await (projectSdk.tablesDB as unknown).deleteAttribute({
      databaseId,
      tableId,
      key: columnKey,
    })
  } else if (
    typeof (projectSdk.tablesDB as unknown).deleteColumn === 'function'
  ) {
    return await (projectSdk.tablesDB as unknown).deleteColumn({
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

  if (typeof (projectSdk.tablesDB as unknown).createIndex === 'function') {
    return await (projectSdk.tablesDB as unknown).createIndex({
      databaseId,
      tableId,
      ...indexData,
    })
  } else {
    throw new Error('Create index method not available')
  }
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

  if (typeof (projectSdk.tablesDB as unknown).deleteIndex === 'function') {
    return await (projectSdk.tablesDB as unknown).deleteIndex({
      databaseId,
      tableId,
      key: indexKey,
    })
  } else {
    throw new Error('Delete index method not available')
  }
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

  if (typeof (projectSdk.tablesDB as unknown).updateTable === 'function') {
    return await (projectSdk.tablesDB as unknown).updateTable({
      databaseId,
      tableId,
      ...data,
    })
  } else if (
    typeof (projectSdk.tablesDB as unknown).updateCollection === 'function'
  ) {
    return await (projectSdk.tablesDB as unknown).updateCollection({
      databaseId,
      tableId,
      ...data,
    })
  } else {
    throw new Error('Update table method not available')
  }
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

  if (typeof (projectSdk.tablesDB as unknown).deleteTable === 'function') {
    return await (projectSdk.tablesDB as unknown).deleteTable({
      databaseId,
      tableId,
    })
  } else if (
    typeof (projectSdk.tablesDB as unknown).deleteCollection === 'function'
  ) {
    return await (projectSdk.tablesDB as unknown).deleteCollection({
      databaseId,
      tableId,
    })
  } else {
    throw new Error('Delete table method not available')
  }
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
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
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
      // Get table count and row count if available
      // These might need to be fetched separately or calculated
      const tables = db.collections?.length || 0
      const rows = db.documents || 0 // This might not be available directly

      // Check for backup policies - check multiple possible field names
      const backupPolicies =
        db.backupPolicies || db.backups || db.policies || db.backup || []
      const backupPoliciesArray = Array.isArray(backupPolicies)
        ? backupPolicies
        : backupPolicies
          ? [backupPolicies]
          : []
      const backupPolicyCount = backupPoliciesArray.length
      const hasBackupPolicy =
        backupPolicyCount > 0 ||
        db.backupEnabled === true ||
        db.backupPolicyEnabled === true
      const backupPolicy = backupPoliciesArray[0] || null

      return {
        $id: db.$id,
        name: db.name || 'Unnamed Database',
        tables,
        rows,
        enabled: db.enabled !== false, // Default to true if not specified
        createdAt: db.$createdAt || new Date().toISOString(),
        updatedAt: db.$updatedAt || db.$createdAt || new Date().toISOString(),
        hasBackupPolicy,
        backupPolicy,
        backupPolicyCount,
      } as Database & {
        enabled: boolean
        createdAt: string
        updatedAt: string
        hasBackupPolicy: boolean
        backupPolicy: unknown
        backupPolicyCount: number
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

    return tablesData.tables.map((table: unknown) => ({
      $id: table.$id,
      name: table.name || 'Unnamed Table',
      databaseId: databaseId || '',
      rows: table.total || 0, // Total rows count if available
      columns: table.attributes?.length || 0, // Column count from attributes
      indexes: table.indexes?.length || 0, // Index count if available
      enabled: table.enabled !== false, // Default to true if not specified
    })) as Collection[]
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
