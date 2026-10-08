import type { QueryClient } from '@tanstack/react-query'
import {
  DatabaseType,
  isNativeDatabaseTypeValue,
} from '@/lib/databases/database-type'
import {
  databaseRouteKindFromApiType,
  dbNavLink,
  productDatabaseListLink,
  type TanStackNavLink,
} from '@/lib/database-routes'
import {
  isMysqlEngine,
  isMongoEngine,
  isPostgresEngine,
} from '@/lib/databases/native-database-engines'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { tablesQueryOptions } from '@/lib/react-query/hooks/databases'
import { postgresDatabaseHome } from '@/lib/postgres-database-routes'
import { mysqlDatabaseHome } from '@/lib/mysql-database-routes'

export type DatabaseSwitcherSelection = {
  id: string
  apiType?: string | null
  engine?: string | null
  product?: string | null
}

function normalizeKey(value: string | null | undefined): string {
  return String(value ?? '')
    .trim()
    .toLowerCase()
    .replace(/-/g, '')
}

function isAppwriteProductKey(key: string): boolean {
  return (
    key === 'tablesdb' ||
    key === 'documentsdb' ||
    key === 'vectorsdb' ||
    key === String(DatabaseType.Tablesdb).toLowerCase() ||
    key === String(DatabaseType.Documentsdb).toLowerCase() ||
    key === String(DatabaseType.Vectorsdb).toLowerCase()
  )
}

function resolveProductDatabaseType(
  selection: DatabaseSwitcherSelection,
): DatabaseType {
  const apiType = normalizeKey(selection.apiType)
  const product = normalizeKey(selection.product)
  for (const key of [apiType, product]) {
    if (key === 'documentsdb') return DatabaseType.Documentsdb
    if (key === 'vectorsdb') return DatabaseType.Vectorsdb
    if (key === 'tablesdb') return DatabaseType.Tablesdb
  }
  return DatabaseType.Tablesdb
}

/**
 * Home link for the unified database switcher (product + native).
 * Appwrite product types win over dedicated compute engines.
 */
export function resolveDatabaseSwitcherHomeLink(
  projectId: string,
  selection: DatabaseSwitcherSelection,
): TanStackNavLink {
  const product = normalizeKey(selection.product)
  const apiType = normalizeKey(selection.apiType)
  const hasAppwriteProduct =
    isAppwriteProductKey(apiType) || isAppwriteProductKey(product)
  const isNativeProduct =
    isNativeDatabaseTypeValue(product) || isNativeDatabaseTypeValue(apiType)
  const engineHint = selection.engine ?? selection.apiType ?? selection.product
  const isNativeEngineOnly =
    !hasAppwriteProduct &&
    !isNativeProduct &&
    apiType !== 'legacy' &&
    apiType !== 'databases' &&
    (isPostgresEngine(engineHint ?? undefined) ||
      isMysqlEngine(engineHint ?? undefined) ||
      isMongoEngine(engineHint ?? undefined))

  if (isNativeProduct || isNativeEngineOnly) {
    if (isPostgresEngine(engineHint ?? undefined)) {
      const link = postgresDatabaseHome({
        projectId,
        databaseId: selection.id,
        tableId: '-',
      })
      return {
        to: link.to,
        params: link.params as unknown as Record<string, string>,
      }
    }

    if (isMysqlEngine(engineHint ?? undefined)) {
      const link = mysqlDatabaseHome({
        projectId,
        databaseId: selection.id,
        tableId: '-',
      })
      return {
        to: link.to,
        params: link.params as unknown as Record<string, string>,
      }
    }
  }

  const type = resolveProductDatabaseType(selection)
  const link = productDatabaseListLink(projectId, selection.id, type)
  return {
    to: link.to,
    params: link.params as unknown as Record<string, string>,
  }
}

function isNativeDatabaseHomeLink(link: TanStackNavLink): boolean {
  const to = String(link.to)
  return (
    to.includes('/databases/postgres/') || to.includes('/databases/mysql/')
  )
}

type NavigateFn = (opts: {
  to: string
  params: Record<string, string>
}) => void

/**
 * Navigate from the shared database switcher to the correct product or
 * native database home (first table when available for Appwrite DBs).
 */
export async function navigateToDatabaseFromSwitcher(options: {
  projectId: string
  selection: DatabaseSwitcherSelection
  navigate: NavigateFn
  queryClient: QueryClient
}): Promise<void> {
  const { projectId, selection, navigate, queryClient } = options
  const home = resolveDatabaseSwitcherHomeLink(projectId, selection)

  if (isNativeDatabaseHomeLink(home)) {
    navigate(home)
    return
  }

  const dbKind = databaseRouteKindFromApiType(
    resolveProductDatabaseType(selection),
  )

  try {
    const tablesData = await queryClient.ensureQueryData(
      tablesQueryOptions(
        projectId,
        selection.id,
        dbKind,
        0,
        ROWS_DEFAULT_PAGE_SIZE,
        undefined,
        'asc',
        '$createdAt',
      ),
    )
    const firstTable = (tablesData.tables || [])[0] as
      | { $id?: string }
      | undefined
    const link = dbNavLink(dbKind).dataGrid({
      projectId,
      dbKind,
      databaseId: selection.id,
      resourceId: firstTable?.$id ?? '-',
    })
    navigate({
      to: link.to,
      params: link.params as unknown as Record<string, string>,
    })
  } catch {
    navigate(home)
  }
}
