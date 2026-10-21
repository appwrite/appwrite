/**
 * Product / legacy database type values used for console routing.
 *
 * Mirrors the product-facing members of `DatabaseType` from
 * `@appwrite.io/console`. `Models.Database.type` is that SDK enum (product APIs
 * plus native engines). Prefer these constants for product-API routing; use
 * helpers below when you need to interpret engine values or coerce to a product.
 */
export enum DatabaseType {
  Legacy = 'legacy',
  Tablesdb = 'tablesdb',
  Documentsdb = 'documentsdb',
  Vectorsdb = 'vectorsdb',
  Mysql = 'mysql',
  Postgresql = 'postgresql',
  Mongodb = 'mongodb',
}

/**
 * Dedicated `api` / historical `product` values.
 *
 * `nativedb` remains for backwards-compatible display of older payloads.
 * New native databases use the engine name as `api` instead.
 */
export enum DatabaseProduct {
  Nativedb = 'nativedb',
  Tablesdb = 'tablesdb',
  Documentsdb = 'documentsdb',
  Vectorsdb = 'vectorsdb',
}

const NATIVE_ENGINE_TYPE_SET = new Set([
  'mysql',
  'mariadb',
  'postgresql',
  'postgres',
  'mongodb',
  'mongo',
])

function normalizeDatabaseTypeKey(value: string | null | undefined): string {
  return String(value ?? '')
    .trim()
    .toLowerCase()
    .replace(/-/g, '')
}

/** True when `Models.Database.type` (or dedicated `api`) is a native engine. */
export function isNativeDatabaseTypeValue(
  type: string | null | undefined,
): boolean {
  const key = normalizeDatabaseTypeKey(type)
  return key === 'nativedb' || NATIVE_ENGINE_TYPE_SET.has(key)
}

/**
 * Engine hint from a unified database `type` string, or null for product/legacy.
 */
export function engineFromDatabaseTypeValue(
  type: string | null | undefined,
): string | null {
  const key = normalizeDatabaseTypeKey(type)
  if (!NATIVE_ENGINE_TYPE_SET.has(key)) return null
  if (key === 'postgres') return 'postgresql'
  if (key === 'mongo') return 'mongodb'
  return key
}

/**
 * Product API hint from a unified database `type` string, or null for native.
 */
export function productFromDatabaseTypeValue(
  type: string | null | undefined,
): string | null {
  const key = normalizeDatabaseTypeKey(type)
  if (
    key === 'tablesdb' ||
    key === 'documentsdb' ||
    key === 'vectorsdb' ||
    key === 'legacy'
  ) {
    return key === 'legacy' ? 'tablesdb' : key
  }
  if (key === 'nativedb' || NATIVE_ENGINE_TYPE_SET.has(key)) {
    return key === 'nativedb' ? DatabaseProduct.Nativedb : key
  }
  return null
}

/**
 * Coerce a unified `type` string into a product `DatabaseType` for routing.
 * Native engine types fall back to TablesDB (they use native routes instead).
 */
export function coerceDatabaseType(
  value: string | null | undefined,
): DatabaseType {
  const key = normalizeDatabaseTypeKey(value)
  if (key === 'documentsdb') return DatabaseType.Documentsdb
  if (key === 'vectorsdb') return DatabaseType.Vectorsdb
  return DatabaseType.Tablesdb
}

/**
 * Cast a type string into the SDK `Models.Database.type` enum member.
 * Preserves known product and engine values; unknown values coerce to TablesDB.
 */
export function toSdkDatabaseType(
  value: string | null | undefined,
): import('@appwrite.io/console').DatabaseType {
  const key = normalizeDatabaseTypeKey(value)
  switch (key) {
    case 'legacy':
      return DatabaseType.Legacy as unknown as import('@appwrite.io/console').DatabaseType
    case 'tablesdb':
      return DatabaseType.Tablesdb as unknown as import('@appwrite.io/console').DatabaseType
    case 'documentsdb':
      return DatabaseType.Documentsdb as unknown as import('@appwrite.io/console').DatabaseType
    case 'vectorsdb':
      return DatabaseType.Vectorsdb as unknown as import('@appwrite.io/console').DatabaseType
    case 'mysql':
      return DatabaseType.Mysql as unknown as import('@appwrite.io/console').DatabaseType
    case 'postgresql':
    case 'postgres':
      return DatabaseType.Postgresql as unknown as import('@appwrite.io/console').DatabaseType
    case 'mongodb':
    case 'mongo':
      return DatabaseType.Mongodb as unknown as import('@appwrite.io/console').DatabaseType
    default:
      return DatabaseType.Tablesdb as unknown as import('@appwrite.io/console').DatabaseType
  }
}
