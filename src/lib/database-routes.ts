import { DatabaseType } from '@appwrite.io/console'

/**
 * URL / route-file segment for the three database products.
 * Paths look like: /projects/:projectId/databases/:dbKind/:databaseId/...
 */
export type DatabaseRouteKind = 'tablesdb' | 'documentsdb' | 'vectorsdb'

const KIND_SET: ReadonlySet<string> = new Set([
  'tablesdb',
  'documentsdb',
  'vectorsdb',
])

export function isDatabaseRouteKind(value: string): value is DatabaseRouteKind {
  return KIND_SET.has(value)
}

export function databaseRouteKindFromApiType(
  type: DatabaseType | undefined,
): DatabaseRouteKind {
  if (type === DatabaseType.Documentsdb) return 'documentsdb'
  if (type === DatabaseType.Vectorsdb) return 'vectorsdb'
  return 'tablesdb'
}

/** TanStack `to` for database root (trailing segment; index route redirects into a table). */
export const DATABASE_HOME_TO =
  '/projects/$projectId/databases/$dbKind/$databaseId/' as const

/** Typed route prefix for navigations (use with `to` + `params`). */
export const DATABASE_TABLES_BASE_TO =
  '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId' as const

export const DATABASE_COLLECTIONS_BASE_TO =
  '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId' as const

/** Documents DB and Vectors DB use `collections/...` URLs; Tables DB uses `tables/...`. */
export function usesCollectionsPath(kind: DatabaseRouteKind): boolean {
  return kind === 'documentsdb' || kind === 'vectorsdb'
}

export type DbNavLinkParams = {
  projectId: string
  dbKind: DatabaseRouteKind
  databaseId: string
  resourceId: string
}

/**
 * Build `to` + `params` for database container routes (tables vs collections).
 * `resourceId` is the API container id (SDK `tableId` / `collectionId`).
 */
export function dbNavLink(kind: DatabaseRouteKind) {
  const coll = usesCollectionsPath(kind)
  const resource = (resourceId: string) =>
    coll ? { collectionId: resourceId } : { tableId: resourceId }
  const base = (p: DbNavLinkParams) => ({
    projectId: p.projectId,
    dbKind: p.dbKind,
    databaseId: p.databaseId,
    ...resource(p.resourceId),
  })

  /** Params for routes scoped to the database only (no table/collection segment). */
  const baseDatabaseOnly = (p: DbNavLinkParams) => ({
    projectId: p.projectId,
    dbKind: p.dbKind,
    databaseId: p.databaseId,
  })

  return {
    dataGrid(p: DbNavLinkParams) {
      return {
        to: coll
          ? '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents'
          : '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/rows',
        params: base(p),
      }
    },
    dataJson(p: DbNavLinkParams) {
      return {
        to: coll
          ? '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/json'
          : '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/documents',
        params: base(p),
      }
    },
    columns(p: DbNavLinkParams) {
      return {
        to: coll
          ? '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/columns'
          : '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/columns',
        params: base(p),
      }
    },
    indexes(p: DbNavLinkParams) {
      return {
        to: coll
          ? '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/indexes'
          : '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/indexes',
        params: base(p),
      }
    },
    security(p: DbNavLinkParams) {
      return {
        to: coll
          ? '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/security'
          : '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/security',
        params: base(p),
      }
    },
    settings(p: DbNavLinkParams) {
      return {
        to: coll
          ? '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/settings'
          : '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/settings',
        params: base(p),
      }
    },
    visualizer(p: DbNavLinkParams) {
      return {
        to: '/projects/$projectId/databases/$dbKind/$databaseId/visualizer',
        params: baseDatabaseOnly(p),
      }
    },
    backups(p: DbNavLinkParams) {
      return {
        to: '/projects/$projectId/databases/$dbKind/$databaseId/backups',
        params: baseDatabaseOnly(p),
      }
    },
    exportImport(p: DbNavLinkParams) {
      return {
        to: '/projects/$projectId/databases/$dbKind/$databaseId/export-import',
        params: baseDatabaseOnly(p),
      }
    },
    insights(p: DbNavLinkParams) {
      return {
        to: '/projects/$projectId/databases/$dbKind/$databaseId/insights',
        params: baseDatabaseOnly(p),
      }
    },
    monitor(p: DbNavLinkParams) {
      return {
        to: '/projects/$projectId/databases/$dbKind/$databaseId/monitor',
        params: baseDatabaseOnly(p),
      }
    },
    dbSecurity(p: DbNavLinkParams) {
      return {
        to: '/projects/$projectId/databases/$dbKind/$databaseId/db-security',
        params: baseDatabaseOnly(p),
      }
    },
    dbSettings(p: DbNavLinkParams) {
      return {
        to: '/projects/$projectId/databases/$dbKind/$databaseId/settings',
        params: baseDatabaseOnly(p),
      }
    },
  }
}
