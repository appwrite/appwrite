import { redirect } from '@tanstack/react-router'
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
  | 'visualizer'
  | 'backups'
  | 'export-import'
  | 'insights'
  | 'db-security'
  | 'db-settings'
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
  visualizer:
    '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/visualizer',
  backups:
    '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/backups',
  'export-import':
    '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/export-import',
  insights:
    '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/insights',
  'db-security':
    '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/db-security',
  'db-settings':
    '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/db-settings',
  columns:
    '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/columns',
}

type DbIdParams = {
  projectId: string
  dbKind: string
  databaseId: string
  collectionId: string
}

/** Call at the start of `collections/...` route loaders when the URL must be tables-only. */
export function throwRedirectTablesDbFromCollectionsChild(
  dbKind: string,
  tab: keyof typeof COLLECTIONS_TO_TABLES_ROUTE,
  p: DbIdParams,
): void {
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
  | 'visualizer'
  | 'backups'
  | 'export-import'
  | 'insights'
  | 'db-security'
  | 'db-settings'
  | 'columns',
  string
> = {
  dataGrid:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
  dataJson:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/json',
  indexes:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/indexes',
  security:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/security',
  settings:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/settings',
  visualizer:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/visualizer',
  backups:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/backups',
  'export-import':
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/export-import',
  insights:
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/insights',
  'db-security':
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/db-security',
  'db-settings':
    '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/db-settings',
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
  tab: keyof typeof TABLES_TO_COLLECTIONS_ROUTE,
  p: TableIdParams,
): void {
  if (dbKind !== 'documentsdb' && dbKind !== 'vectorsdb') return
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
