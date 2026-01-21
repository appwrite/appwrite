/**
 * React Query hooks for Databases
 * 
 * Handles databases, tables, rows, columns, and indexes.
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query, ID } from '@appwrite.io/console'
import type { Database, Collection } from '@/lib/utils/mock-data'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME, DEFAULT_PAGE_SIZE, keepPreviousData } from './constants'

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
) {
  if (!projectId) {
    return { databases: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  // Use TablesDB API to list databases
  let response: any
  try {
    response = await (projectSdk.tablesDB as any).list(
      queries,
      search?.trim() || undefined
    )
  } catch (err) {
    console.warn('Failed to fetch databases:', err)
    response = { databases: [], total: 0 }
  }

  return {
    databases: response?.databases || [],
    total: response?.total || 0,
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
    const db = await (projectSdk.tablesDB as any).get(databaseId)

    if (!db) {
      return null
    }

    // Map to our Database type
    const backupPolicies = db.backupPolicies || db.backups || db.policies || db.backup || []
    const backupPoliciesArray = Array.isArray(backupPolicies) ? backupPolicies : (backupPolicies ? [backupPolicies] : [])
    const backupPolicyCount = backupPoliciesArray.length
    const hasBackupPolicy = backupPolicyCount > 0 || db.backupEnabled === true || db.backupPolicyEnabled === true
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
    } as Database & { enabled: boolean; createdAt: string; updatedAt: string; hasBackupPolicy: boolean; backupPolicy: any; backupPolicyCount: number }
  } catch (err) {
    console.error('[fetchProjectDatabase] Failed to fetch database:', err)
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
  const databaseId = (data.databaseId && data.databaseId.trim() !== '') ? data.databaseId.trim() : ID.unique()
  return await (projectSdk.tablesDB as any).create({
    databaseId,
    name: data.name.trim(),
  })
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
export async function fetchProjectTables(
  projectId: string,
  databaseId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  if (!projectId || !databaseId) {
    return { tables: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  // Use TablesDB API to list tables (collections)
  let response: any
  try {
    response = await (projectSdk.tablesDB as any).listTables(
      databaseId,
      queries,
      search?.trim() || undefined
    )
  } catch (err) {
    console.warn('Failed to fetch tables:', err)
    response = { tables: [], total: 0 }
  }

  return {
    tables: response?.tables ?? [],
    total: response?.total || 0,
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
  let response: any
  try {
    response = await (projectSdk.tablesDB as any).listTables(
      databaseId,
      queries,
      undefined
    )
  } catch (err) {
    console.warn('Failed to fetch tables for visualizer:', err)
    response = { tables: [] }
  }

  return {
    tables: response?.tables ?? [],
  }
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
 * @returns Paginated rows with total count
 */
export async function fetchProjectTableRows(
  projectId: string,
  databaseId: string,
  tableId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  if (!projectId || !databaseId || !tableId) {
    return { rows: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  // Use TablesDB API to list rows
  let response: any
  try {
    if (typeof projectSdk.tablesDB.listRows === 'function') {
      // Note: search might need to be passed differently depending on SDK version
      const listRowsParams: any = {
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
  } catch (err) {
    console.warn('Failed to fetch rows:', err)
    response = { rows: [], total: 0 }
  }

  return {
    rows: response.rows || response.documents || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch columns (attributes) for a table
 * 
 * This is extracted so it can be reused in both hooks and route loaders.
 * 
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @returns Columns data
 */
export async function fetchProjectTableColumns(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  if (!projectId || !databaseId || !tableId) {
    return { columns: [] }
  }

  const projectSdk = sdk.forProject(projectId)
  
  // Use TablesDB API to get table details which includes attributes/columns
  let response: any
  try {
    if (typeof (projectSdk.tablesDB as any).getTable === 'function') {
      response = await (projectSdk.tablesDB as any).getTable(databaseId, tableId)
    } else if (typeof (projectSdk.tablesDB as any).getCollection === 'function') {
      response = await (projectSdk.tablesDB as any).getCollection(databaseId, tableId)
    } else {
      response = { attributes: [] }
    }
  } catch (err) {
    console.warn('Failed to fetch columns:', err)
    response = { attributes: [] }
  }

  return {
    columns: response.attributes || response.columns || [],
  }
}

/**
 * Query function to fetch indexes for a table
 * 
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @returns Indexes data
 */
export async function fetchProjectTableIndexes(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  if (!projectId || !databaseId || !tableId) {
    return { indexes: [] }
  }

  const projectSdk = sdk.forProject(projectId)
  
  let response: any
  try {
    if (typeof (projectSdk.tablesDB as any).getTable === 'function') {
      response = await (projectSdk.tablesDB as any).getTable(databaseId, tableId)
    } else if (typeof (projectSdk.tablesDB as any).getCollection === 'function') {
      response = await (projectSdk.tablesDB as any).getCollection(databaseId, tableId)
    } else {
      response = { indexes: [] }
    }
  } catch (err) {
    console.warn('Failed to fetch indexes:', err)
    response = { indexes: [] }
  }

  return {
    indexes: response.indexes || [],
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
    let response: any
    if (typeof (projectSdk.tablesDB as any).getTable === 'function') {
      response = await (projectSdk.tablesDB as any).getTable(databaseId, tableId)
    } else if (typeof (projectSdk.tablesDB as any).getCollection === 'function') {
      response = await (projectSdk.tablesDB as any).getCollection(databaseId, tableId)
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
      $updatedAt: response.$updatedAt || response.$createdAt || new Date().toISOString(),
    }
  } catch (err) {
    console.error('[fetchProjectTable] Failed to fetch table:', err)
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
  data: Record<string, any>,
  rowId?: string,
  permissions?: string[],
) {
  if (!projectId || !databaseId || !tableId) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  
  if (typeof projectSdk.tablesDB.createRow === 'function') {
    const { ID } = await import('@appwrite.io/console')
    const createParams: any = {
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
  data: Record<string, any>,
  permissions?: string[],
) {
  if (!projectId || !databaseId || !tableId || !rowId) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  
  if (typeof projectSdk.tablesDB.updateRow === 'function') {
    const updateParams: any = {
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
  rows: Record<string, any>[],
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
  if (!hasRelationshipColumns && typeof (projectSdk.tablesDB as any).createRows === 'function') {
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

      await (projectSdk.tablesDB as any).createRows({
        databaseId,
        tableId,
        rows: rowsToInsert,
      })
      created = rows.length
    } catch (error) {
      // If bulk insert fails, fall back to individual inserts
      console.warn('Bulk insert failed, falling back to individual inserts:', error)
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
          await createProjectTableRow(projectId, databaseId, tableId, row, row.$id)
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
  columnData: any,
) {
  if (!projectId || !databaseId || !tableId) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  
  if (typeof (projectSdk.tablesDB as any).createAttribute === 'function') {
    return await (projectSdk.tablesDB as any).createAttribute({
      databaseId,
      tableId,
      ...columnData,
    })
  } else if (typeof (projectSdk.tablesDB as any).createColumn === 'function') {
    return await (projectSdk.tablesDB as any).createColumn({
      databaseId,
      tableId,
      ...columnData,
    })
  } else {
    throw new Error('Create column method not available')
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
  columnData: any,
) {
  if (!projectId || !databaseId || !tableId || !columnKey) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  
  if (typeof (projectSdk.tablesDB as any).updateAttribute === 'function') {
    return await (projectSdk.tablesDB as any).updateAttribute({
      databaseId,
      tableId,
      key: columnKey,
      ...columnData,
    })
  } else if (typeof (projectSdk.tablesDB as any).updateColumn === 'function') {
    return await (projectSdk.tablesDB as any).updateColumn({
      databaseId,
      tableId,
      key: columnKey,
      ...columnData,
    })
  } else {
    throw new Error('Update column method not available')
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
  
  if (typeof (projectSdk.tablesDB as any).deleteAttribute === 'function') {
    return await (projectSdk.tablesDB as any).deleteAttribute({
      databaseId,
      tableId,
      key: columnKey,
    })
  } else if (typeof (projectSdk.tablesDB as any).deleteColumn === 'function') {
    return await (projectSdk.tablesDB as any).deleteColumn({
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
  indexData: any,
) {
  if (!projectId || !databaseId || !tableId) {
    throw new Error('Missing required parameters')
  }

  const projectSdk = sdk.forProject(projectId)
  
  if (typeof (projectSdk.tablesDB as any).createIndex === 'function') {
    return await (projectSdk.tablesDB as any).createIndex({
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
  
  if (typeof (projectSdk.tablesDB as any).deleteIndex === 'function') {
    return await (projectSdk.tablesDB as any).deleteIndex({
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
  
  if (typeof (projectSdk.tablesDB as any).updateTable === 'function') {
    return await (projectSdk.tablesDB as any).updateTable({
      databaseId,
      tableId,
      ...data,
    })
  } else if (typeof (projectSdk.tablesDB as any).updateCollection === 'function') {
    return await (projectSdk.tablesDB as any).updateCollection({
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
  
  if (typeof (projectSdk.tablesDB as any).deleteTable === 'function') {
    return await (projectSdk.tablesDB as any).deleteTable({
      databaseId,
      tableId,
    })
  } else if (typeof (projectSdk.tablesDB as any).deleteCollection === 'function') {
    return await (projectSdk.tablesDB as any).deleteCollection({
      databaseId,
      tableId,
    })
  } else {
    throw new Error('Delete table method not available')
  }
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
) {
  const {
    data: databasesData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery({
    queryKey: ['databases', 'project', projectId, page, limit, search],
    queryFn: () => fetchProjectDatabases(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
  })

  // Map databases to our Database type
  const databases = useMemo(() => {
    if (!databasesData?.databases) return []
    
    return databasesData.databases.map((db: any) => {
      // Get table count and row count if available
      // These might need to be fetched separately or calculated
      const tables = db.collections?.length || 0
      const rows = db.documents || 0 // This might not be available directly
      
      // Check for backup policies - check multiple possible field names
      const backupPolicies = db.backupPolicies || db.backups || db.policies || db.backup || []
      const backupPoliciesArray = Array.isArray(backupPolicies) ? backupPolicies : (backupPolicies ? [backupPolicies] : [])
      const backupPolicyCount = backupPoliciesArray.length
      const hasBackupPolicy = backupPolicyCount > 0 || db.backupEnabled === true || db.backupPolicyEnabled === true
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
      } as Database & { enabled: boolean; createdAt: string; updatedAt: string; hasBackupPolicy: boolean; backupPolicy: any; backupPolicyCount: number }
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
  const isEnabled = !!projectId && !!databaseId
  
  const {
    data: databaseData,
    isLoading,
    isPending,
    error,
    refetch,
  } = useQuery({
    queryKey: ['database', 'project', projectId, databaseId],
    queryFn: () => fetchProjectDatabase(projectId!, databaseId!),
    enabled: isEnabled,
    staleTime: DEFAULT_STALE_TIME,
  })

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
  } = useQuery({
    queryKey: ['tables', 'project', projectId, databaseId, page, limit, normalizedSearch],
    queryFn: () => fetchProjectTables(projectId!, databaseId!, page, limit, normalizedSearch),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
  })

  // Map tables to our Collection type
  const tables = useMemo(() => {
    if (!tablesData?.tables) return []
    
    return tablesData.tables.map((table: any) => ({
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
  } = useQuery({
    queryKey: ['tables', 'visualizer', 'project', projectId, databaseId],
    queryFn: () => fetchAllProjectTablesForVisualizer(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
  })

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
 * @returns Paginated rows with loading state
 */
export function useProjectTableRows(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const {
    data: rowsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery({
    queryKey: ['rows', 'project', projectId, databaseId, tableId, page, limit, search],
    queryFn: () => fetchProjectTableRows(projectId!, databaseId!, tableId!, page, limit, search),
    enabled: !!projectId && !!databaseId && !!tableId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
  })

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
 * Hook to fetch columns (attributes) for a table
 * 
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @returns Columns with loading state
 */
export function useProjectTableColumns(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const {
    data: columnsData,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['columns', 'project', projectId, databaseId, tableId],
    queryFn: () => fetchProjectTableColumns(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId,
    staleTime: DEFAULT_STALE_TIME,
  })

  return {
    columns: columnsData?.columns || [],
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch indexes for a table
 * 
 * @param projectId - The project ID
 * @param databaseId - The database ID
 * @param tableId - The table ID
 * @returns Indexes with loading state
 */
export function useProjectTableIndexes(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const {
    data: indexesData,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['indexes', 'project', projectId, databaseId, tableId],
    queryFn: () => fetchProjectTableIndexes(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId,
    staleTime: DEFAULT_STALE_TIME,
  })

  return {
    indexes: indexesData?.indexes || [],
    isLoading,
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
  const isEnabled = !!projectId && !!databaseId && !!tableId
  
  const {
    data: tableData,
    isLoading,
    isPending,
    error,
    refetch,
  } = useQuery({
    queryKey: ['table', 'project', projectId, databaseId, tableId],
    queryFn: () => fetchProjectTable(projectId!, databaseId!, tableId!),
    enabled: isEnabled,
    staleTime: DEFAULT_STALE_TIME,
  })

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
      data: Record<string, any>
      rowId?: string
      permissions?: string[]
    }) => {
      if (!projectId || !databaseId || !tableId) {
        throw new Error('Missing required parameters')
      }
      return await createProjectTableRow(projectId, databaseId, tableId, data, rowId, permissions)
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
      data: Record<string, any>
      permissions?: string[]
    }) => {
      if (!projectId || !databaseId || !tableId || !rowId) {
        throw new Error('Missing required parameters')
      }
      return await updateProjectTableRow(projectId, databaseId, tableId, rowId, data, permissions)
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
      rows: Record<string, any>[]
      hasRelationshipColumns?: boolean
    }) => {
      if (!projectId || !databaseId || !tableId) {
        throw new Error('Missing required parameters')
      }
      return await createProjectTableRows(projectId, databaseId, tableId, rows, hasRelationshipColumns)
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
    mutationFn: async (columnData: any) => {
      if (!projectId || !databaseId || !tableId) {
        throw new Error('Missing required parameters')
      }
      return await createProjectTableColumn(projectId, databaseId, tableId, columnData)
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
      columnData: any
    }) => {
      if (!projectId || !databaseId || !tableId || !columnKey) {
        throw new Error('Missing required parameters')
      }
      return await updateProjectTableColumn(projectId, databaseId, tableId, columnKey, columnData)
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
      return await deleteProjectTableColumn(projectId, databaseId, tableId, columnKey)
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
    mutationFn: async (indexData: any) => {
      if (!projectId || !databaseId || !tableId) {
        throw new Error('Missing required parameters')
      }
      return await createProjectTableIndex(projectId, databaseId, tableId, indexData)
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
      return await deleteProjectTableIndex(projectId, databaseId, tableId, indexKey)
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
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      queryClient.invalidateQueries({
        queryKey: ['databases', 'project', projectId],
      })
    },
  })
}
