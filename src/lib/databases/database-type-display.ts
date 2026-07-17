import {
  DatabaseType,
  isNativeDatabaseTypeValue,
} from '@/lib/databases/database-type'
import {
  formatDatabaseServiceLabel,
  getDatabaseServiceLucideIcon,
} from '@/lib/databases/database-service-icons'
import {
  isMongoEngine,
  isMysqlEngine,
  isPostgresEngine,
  NATIVE_DATABASE_ENGINE_LABELS,
} from '@/lib/databases/native-database-engines'
import type { LucideIcon } from '@/lib/icons'

export type DatabaseTypeDisplayHints = {
  /**
   * Appwrite SDK `database.type`: product API (`tablesdb` / `documentsdb` /
   * `vectorsdb` / `legacy`) or native engine (`mysql` / `postgresql` /
   * `mongodb`).
   */
  apiType?: string | null
  /** Dedicated / native engine (postgresql, mysql, mongodb). */
  engine?: string | null
  /**
   * Dedicated `api` (or legacy `product`): product API name, engine name for
   * native DBs, or historical `nativedb`.
   */
  product?: string | null
}

function normalizeKey(value: string | null | undefined): string {
  return String(value ?? '')
    .trim()
    .toLowerCase()
    .replace(/-/g, '')
}

function isNativeProduct(product: string): boolean {
  return isNativeDatabaseTypeValue(product)
}

function resolveAppwriteProductType(
  apiType: string,
  product: string,
): string | null {
  for (const key of [apiType, product]) {
    if (
      key === 'tablesdb' ||
      key === String(DatabaseType.Tablesdb).toLowerCase() ||
      key === 'documentsdb' ||
      key === String(DatabaseType.Documentsdb).toLowerCase() ||
      key === 'vectorsdb' ||
      key === String(DatabaseType.Vectorsdb).toLowerCase()
    ) {
      return key
    }
  }
  return null
}

/** Label for a native database engine, or null when not a known engine. */
export function getDatabaseEngineDisplayLabel(
  engine?: string | null,
): string | null {
  const normalized = normalizeKey(engine)
  if (!normalized) return null
  if (isPostgresEngine(normalized)) return NATIVE_DATABASE_ENGINE_LABELS.postgres
  if (isMysqlEngine(normalized)) {
    return normalized === 'mariadb' ? 'MariaDB' : NATIVE_DATABASE_ENGINE_LABELS.mysql
  }
  if (isMongoEngine(normalized)) return NATIVE_DATABASE_ENGINE_LABELS.mongo
  return null
}

/**
 * Resolve whether to show an Appwrite product type or a native engine.
 *
 * Appwrite product DBs (TablesDB / DocumentsDB / VectorsDB) always use their
 * product type, even when they have a dedicated compute engine. Native DBs
 * use the engine name. Legacy / missing types fall back to TablesDB.
 */
export function resolveDatabaseTypeDisplay(
  hints: DatabaseTypeDisplayHints,
): { mode: 'product' | 'engine'; key: string; label: string } {
  const apiType = normalizeKey(hints.apiType)
  const product = normalizeKey(hints.product)
  const engineFromHints = hints.engine ?? (isNativeDatabaseTypeValue(apiType) ? hints.apiType : null)
  const engineLabel = getDatabaseEngineDisplayLabel(engineFromHints)

  if (isNativeProduct(product)) {
    return {
      mode: 'engine',
      key: normalizeKey(engineFromHints) || normalizeKey(product) || 'postgres',
      label:
        engineLabel ??
        getDatabaseEngineDisplayLabel(product) ??
        'PostgreSQL',
    }
  }

  const appwriteType = resolveAppwriteProductType(apiType, product)
  if (appwriteType) {
    return {
      mode: 'product',
      key: appwriteType,
      label: formatDatabaseServiceLabel(appwriteType) ?? 'TablesDB',
    }
  }

  // Unified `type` (or dedicated `api`) can itself be a native engine name.
  if (engineLabel) {
    return {
      mode: 'engine',
      key: normalizeKey(engineFromHints),
      label: engineLabel,
    }
  }

  return {
    mode: 'product',
    key: 'tablesdb',
    label: 'TablesDB',
  }
}

/** Human-readable type prefix for selectors, badges, and breadcrumbs. */
export function getDatabaseTypeDisplayLabel(
  apiType?: string | null,
  engine?: string | null,
  product?: string | null,
): string {
  return resolveDatabaseTypeDisplay({ apiType, engine, product }).label
}

/** Lucide icon for Appwrite product types when not using a native engine icon. */
export function getDatabaseTypeDisplayLucideIcon(
  hints: DatabaseTypeDisplayHints,
): LucideIcon | null {
  const resolved = resolveDatabaseTypeDisplay(hints)
  if (resolved.mode !== 'product') return null
  return getDatabaseServiceLucideIcon(resolved.key)
}
