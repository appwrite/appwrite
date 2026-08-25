import {
  DatabaseType,
  engineFromDatabaseTypeValue,
  isNativeDatabaseTypeValue,
} from '@/lib/databases/database-type'
import type { ConsoleProfileFeatures } from '@/lib/console-profiles'
import { postgresDatabaseHome } from '@/lib/postgres-database-routes'
import { mysqlDatabaseHome } from '@/lib/mysql-database-routes'
import { isMysqlEngine } from '@/lib/databases/native-database-engines'

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

/** Whether the console profile exposes this product database route tree. */
export function isProductDatabaseRouteKindEnabled(
  dbKind: DatabaseRouteKind,
  features: ConsoleProfileFeatures,
): boolean {
  if (dbKind === 'documentsdb') return features.dedicatedDbsDocumentsDB
  if (dbKind === 'vectorsdb') return features.dedicatedDbsVectorsDB
  return true
}

/** Whether API calls for this product database type are allowed. */
export function isProductDatabaseTypeEnabled(
  type: DatabaseType,
  features: ConsoleProfileFeatures,
): boolean {
  if (type === DatabaseType.Documentsdb) return features.dedicatedDbsDocumentsDB
  if (type === DatabaseType.Vectorsdb) return features.dedicatedDbsVectorsDB
  return true
}

/**
 * Whether this database type is enabled on the active console profile.
 * TablesDB is always on. DocumentsDB, VectorsDB, and native engines follow
 * their feature flags. Databases whose flag is off can still appear in the
 * unified list and should render as disabled.
 */
export function isDatabaseTypeFeatureEnabled(
  type: string | DatabaseType | null | undefined,
  features: Pick<
    ConsoleProfileFeatures,
    | 'dedicatedDbsDocumentsDB'
    | 'dedicatedDbsVectorsDB'
    | 'nativeDbsPostgres'
    | 'nativeDbsMySQL'
    | 'nativeDbsMongo'
  >,
): boolean {
  const key = String(type ?? '')
    .trim()
    .toLowerCase()
    .replace(/-/g, '')
  if (!key) return true

  if (key === 'documentsdb') return features.dedicatedDbsDocumentsDB
  if (key === 'vectorsdb') return features.dedicatedDbsVectorsDB

  const engine = engineFromDatabaseTypeValue(key)
  if (engine === 'postgresql') return features.nativeDbsPostgres
  if (engine === 'mysql') return features.nativeDbsMySQL
  if (engine === 'mongodb') return features.nativeDbsMongo
  if (isNativeDatabaseTypeValue(key)) return features.nativeDbsPostgres

  return true
}

export function databaseRouteKindFromApiType(
  type: DatabaseType | string | undefined,
): DatabaseRouteKind {
  const key = String(type ?? '')
    .trim()
    .toLowerCase()
  if (key === DatabaseType.Documentsdb || key === 'documentsdb') {
    return 'documentsdb'
  }
  if (key === DatabaseType.Vectorsdb || key === 'vectorsdb') {
    return 'vectorsdb'
  }
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
    monitor(p: DbNavLinkParams) {
      return {
        to: '/projects/$projectId/databases/$dbKind/$databaseId/monitor',
        params: baseDatabaseOnly(p),
      }
    },
    dbSecurity(p: DbNavLinkParams) {
      return {
        to: '/projects/$projectId/databases/$dbKind/$databaseId/settings/security',
        params: baseDatabaseOnly(p),
      }
    },
    dbSettings(p: DbNavLinkParams) {
      return {
        to: '/projects/$projectId/databases/$dbKind/$databaseId/settings',
        params: baseDatabaseOnly(p),
      }
    },
    /** Database settings Specification sub-page. */
    dbSpecificationSettings(p: DbNavLinkParams) {
      return {
        to: '/projects/$projectId/databases/$dbKind/$databaseId/settings/specification',
        params: baseDatabaseOnly(p),
        hash: 'card-specification',
      }
    },
  }
}

const PRODUCT_DEDICATED_API = new Set([
  'tablesdb',
  'documentsdb',
  'vectorsdb',
])

export type DedicatedDatabaseLinkInput = {
  $id: string
  api: string
  engine: string
}

export type TanStackNavLink = {
  to: string
  params: Record<string, string>
}

function dedicatedApiToRouteKind(api: string): DatabaseRouteKind | null {
  const normalized = api.toLowerCase().trim()
  if (normalized === 'documentsdb') return 'documentsdb'
  if (normalized === 'vectorsdb') return 'vectorsdb'
  if (normalized === 'tablesdb') return 'tablesdb'
  return null
}

/** Product-owned dedicated compute (`api` = tablesdb / documentsdb / vectorsdb). */
export function isProductOwnedDedicatedDatabase(
  db: Pick<DedicatedDatabaseLinkInput, 'api'>,
): boolean {
  return dedicatedApiToRouteKind(db.api ?? '') !== null
}

/**
 * Native dedicated databases (`api` is an engine name, historical `nativedb`,
 * or unset), not product-backed.
 */
export function isNativeDedicatedDatabase(
  db: Pick<DedicatedDatabaseLinkInput, 'api'>,
): boolean {
  return !isProductOwnedDedicatedDatabase(db)
}

export function needsDedicatedProductTypeLookup(
  _db: DedicatedDatabaseLinkInput,
): boolean {
  // Never probe documentsDB / vectorsDB / tablesDB by dedicated ID.
  // Product rows carry `api`; native rows use `engine`. Missing `api` must not
  // trigger GET /documentsdb/{id} + /vectorsdb/{id} for TablesDB databases.
  return false
}

export function isPostgresDedicatedEngine(
  engine: string | null | undefined,
): boolean {
  const normalized = engine?.toLowerCase() ?? ''
  return normalized === 'postgres' || normalized === 'postgresql'
}

function productDatabaseDeepLink(
  projectId: string,
  databaseId: string,
  dbKind: DatabaseRouteKind,
): TanStackNavLink {
  const link = dbNavLink(dbKind).dataGrid({
    projectId,
    dbKind,
    databaseId,
    resourceId: '-',
  })
  return {
    to: link.to,
    params: link.params as unknown as Record<string, string>,
  }
}

/** Product database list/card deep link from API `database.type`. */
export function productDatabaseListLink(
  projectId: string,
  databaseId: string,
  type: DatabaseType | undefined,
) {
  const dbKind = databaseRouteKindFromApiType(type)
  return dbNavLink(dbKind).dataGrid({
    projectId,
    dbKind,
    databaseId,
    resourceId: '-',
  })
}

/** Console path for copy/open actions (includes `$dbKind`). */
export function productDatabaseHomePath(
  projectId: string,
  databaseId: string,
  type: DatabaseType | undefined,
): string {
  const dbKind = databaseRouteKindFromApiType(type)
  return `/projects/${projectId}/databases/${dbKind}/${databaseId}/`
}

/** Resolve the console home link for a native or product-backed dedicated database row. */
export function dedicatedDatabaseHomeLink(
  projectId: string,
  db: DedicatedDatabaseLinkInput,
  productRouteKind?: DatabaseRouteKind | null,
): TanStackNavLink | null {
  const apiKind = dedicatedApiToRouteKind(db.api)
  if (apiKind) {
    return productDatabaseDeepLink(projectId, db.$id, apiKind)
  }

  if (productRouteKind) {
    return productDatabaseDeepLink(projectId, db.$id, productRouteKind)
  }

  if (isPostgresDedicatedEngine(db.engine)) {
    return postgresDatabaseHome({
      projectId,
      databaseId: db.$id,
      tableId: '-',
    })
  }

  if (isMysqlEngine(db.engine)) {
    return mysqlDatabaseHome({
      projectId,
      databaseId: db.$id,
      tableId: '-',
    })
  }

  return null
}
