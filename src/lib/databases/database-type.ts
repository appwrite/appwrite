/**
 * Product / legacy database type values.
 *
 * Previously exported as enums from `@appwrite.io/console`. As of console SDK
 * `3a045b5`, `Models.Database.type` is a plain string that can be a product API
 * (`tablesdb` / `documentsdb` / `vectorsdb` / `legacy`) or a native engine
 * (`mysql` / `postgresql` / `mongodb`). Keep these constants for product-API
 * routing in the console UI.
 */
export enum DatabaseType {
  Legacy = 'legacy',
  Tablesdb = 'tablesdb',
  Documentsdb = 'documentsdb',
  Vectorsdb = 'vectorsdb',
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
