import { redirect } from '@tanstack/react-router'
import {
  POSTGRES_DB_KIND,
  isPostgresDatabaseTabSegment,
  postgresDatabaseHome,
  postgresDatabaseTabLink,
} from '@/lib/postgres-database-routes'
import {
  MYSQL_DB_KIND,
  isMysqlDatabaseTabSegment,
  mysqlDatabaseHome,
  mysqlDatabaseTabLink,
} from '@/lib/mysql-database-routes'
import {
  type DatabaseRouteKind,
  usesCollectionsPath,
} from '@/lib/database-routes'

type RedirectOptions = {
  to: string
  params: Record<string, string>
  search?: Record<string, unknown> | true
}

/**
 * Canonical paths for database-wide tabs (same URL for tablesdb, documentsdb, vectorsdb).
 * Not nested under /tables/:id or /collections/:id.
 */
export const DATABASE_LEVEL_TAB_PATH = {
  visualizer: '/projects/$projectId/databases/$dbKind/$databaseId/visualizer',
  backups: '/projects/$projectId/databases/$dbKind/$databaseId/backups',
  'export-import':
    '/projects/$projectId/databases/$dbKind/$databaseId/export-import',
  monitor: '/projects/$projectId/databases/$dbKind/$databaseId/monitor',
  'db-security':
    '/projects/$projectId/databases/$dbKind/$databaseId/settings/security',
  'db-settings': '/projects/$projectId/databases/$dbKind/$databaseId/settings',
} as const

export type DatabaseLevelTab = keyof typeof DATABASE_LEVEL_TAB_PATH

/** Redirect mistaken `$dbKind=postgres` matches to the dedicated Postgres route tree. */
export function throwRedirectPostgresDbKind(
  dbKind: string,
  params: {
    projectId: string
    databaseId: string
    tableId?: string
  },
): void {
  if (dbKind !== POSTGRES_DB_KIND) return

  const tableId = params.tableId?.trim()
  if (tableId && tableId !== '-' && isPostgresDatabaseTabSegment(tableId)) {
    throw redirect({
      ...postgresDatabaseTabLink(
        params.projectId,
        params.databaseId,
        tableId,
      ),
      search: {},
      replace: true,
    })
  }

  throw redirect({
    ...postgresDatabaseHome({
      projectId: params.projectId,
      databaseId: params.databaseId,
      tableId: tableId && tableId !== '-' ? tableId : '-',
    }),
    search: {},
    replace: true,
  })
}

/** Redirect mistaken `$dbKind=mysql` matches to the dedicated MySQL route tree. */
export function throwRedirectMysqlDbKind(
  dbKind: string,
  params: {
    projectId: string
    databaseId: string
    tableId?: string
  },
): void {
  if (dbKind !== MYSQL_DB_KIND) return

  const tableId = params.tableId?.trim()
  if (tableId && tableId !== '-' && isMysqlDatabaseTabSegment(tableId)) {
    throw redirect({
      ...mysqlDatabaseTabLink(params.projectId, params.databaseId, tableId),
      search: {},
      replace: true,
    })
  }

  throw redirect({
    ...mysqlDatabaseHome({
      projectId: params.projectId,
      databaseId: params.databaseId,
      tableId: tableId && tableId !== '-' ? tableId : '-',
    }),
    search: {},
    replace: true,
  })
}

function isDatabaseLevelTab(tab: string): tab is DatabaseLevelTab {
  return Object.prototype.hasOwnProperty.call(DATABASE_LEVEL_TAB_PATH, tab)
}

/**
 * In `tables/...` route loaders: send Documents DB / Vectors DB traffic to the
 * native `collections/...` URLs.
 */
export function throwRedirectIfCollectionsLayout(
  dbKind: string,
  options: RedirectOptions,
): void {
  if (!usesCollectionsPath(dbKind as DatabaseRouteKind)) return
  throw redirect({
    to: options.to,
    params: options.params,
    ...(options.search !== undefined ? { search: options.search } : {}),
    replace: true,
  })
}

/**
 * In `collections/...` route loaders: Tables DB must keep `tables/...` URLs.
 */
export function throwRedirectIfTablesLayout(
  dbKind: string,
  options: RedirectOptions,
): void {
  if (dbKind !== 'tablesdb') return
  throw redirect({
    to: options.to,
    params: options.params,
    ...(options.search !== undefined ? { search: options.search } : {}),
    replace: true,
  })
}

const COLLECTIONS_TO_TABLES_ROUTE: Record<
  | 'dataGrid'
  | 'dataJson'
  | 'indexes'
  | 'security'
  | 'settings'
  | 'columns',
  string
> = {
  dataGrid:
    '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/rows',
  dataJson:
    '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/documents',
  indexes:
    '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/indexes',
  security:
    '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/security',
  settings:
    '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/settings',
  columns:
    '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/columns',
}

type DbIdParams = {
  projectId: string
  dbKind: string
  databaseId: string
  collectionId: string
}

export type CrossLayoutRedirectTab =
  | keyof typeof COLLECTIONS_TO_TABLES_ROUTE
  | DatabaseLevelTab

/** Call at the start of `collections/...` route loaders when the URL must be tables-only. */
export function throwRedirectTablesDbFromCollectionsChild(
  dbKind: string,
  tab: CrossLayoutRedirectTab,
  p: DbIdParams,
): void {
  if (isDatabaseLevelTab(tab)) {
    throw redirect({
      to: DATABASE_LEVEL_TAB_PATH[tab],
      params: {
        projectId: p.projectId,
        dbKind: p.dbKind,
        databaseId: p.databaseId,
      },
      replace: true,
    })
  }
  if (dbKind !== 'tablesdb') return
  throw redirect({
    to: COLLECTIONS_TO_TABLES_ROUTE[tab],
    params: {
      projectId: p.projectId,
      dbKind: p.dbKind,
      databaseId: p.databaseId,
      tableId: p.collectionId,
    },
    replace: true,
  })
}

const TABLES_TO_COLLECTIONS_ROUTE: Record<
  | 'dataGrid'
  | 'dataJson'
  | 'indexes'
  | 'security'
  | 'settings'
  | 'columns',
  string
> = {
  dataGrid:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
  dataJson:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
  indexes:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/indexes',
  security:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/security',
  settings:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/settings',
  columns:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/columns',
}

type TableIdParams = {
  projectId: string
  dbKind: string
  databaseId: string
  tableId: string
}

/** Call at the start of `tables/...` route loaders for Documents / Vectors DB. */
export function throwRedirectCollectionsDbFromTablesChild(
  dbKind: string,
  tab: CrossLayoutRedirectTab,
  p: TableIdParams,
): void {
  if (isDatabaseLevelTab(tab)) {
    throw redirect({
      to: DATABASE_LEVEL_TAB_PATH[tab],
      params: {
        projectId: p.projectId,
        dbKind: p.dbKind,
        databaseId: p.databaseId,
      },
      replace: true,
    })
  }
  if (tab === 'dataJson' && dbKind === 'vectorsdb') {
    throw redirect({
      to: TABLES_TO_COLLECTIONS_ROUTE.dataGrid,
      params: {
        projectId: p.projectId,
        dbKind: p.dbKind,
        databaseId: p.databaseId,
        collectionId: p.tableId,
      },
      replace: true,
    })
  }
  if (dbKind !== 'documentsdb' && dbKind !== 'vectorsdb') return
  throw redirect({
    to: TABLES_TO_COLLECTIONS_ROUTE[tab],
    params: {
      projectId: p.projectId,
      dbKind: p.dbKind,
      databaseId: p.databaseId,
      collectionId: p.tableId,
    },
    replace: true,
  })
}
