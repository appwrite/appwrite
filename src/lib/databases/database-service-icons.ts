import { DatabaseType } from '@appwrite.io/console'
import {
  Braces,
  Database,
  Layers,
  Table as TableIcon,
  type LucideIcon,
} from '@/lib/icons'

function normalizeDatabaseServiceKey(value: string): string {
  return value.trim().toLowerCase().replace(/-/g, '')
}

/** Lucide icons for Appwrite database API service segments (usage, nav, lists). */
export function getDatabaseServiceLucideIcon(
  serviceKey: string,
): LucideIcon | null {
  const key = normalizeDatabaseServiceKey(serviceKey)

  if (
    key === 'tablesdb' ||
    key === String(DatabaseType.Tablesdb).toLowerCase()
  ) {
    return TableIcon
  }
  if (
    key === 'documentsdb' ||
    key === String(DatabaseType.Documentsdb).toLowerCase()
  ) {
    return Braces
  }
  if (
    key === 'vectorsdb' ||
    key === String(DatabaseType.Vectorsdb).toLowerCase()
  ) {
    return Layers
  }
  if (
    key === 'databases' ||
    key === 'legacy' ||
    key === String(DatabaseType.Legacy).toLowerCase()
  ) {
    return Database
  }

  return null
}

/** Human-readable labels for database API service segments. */
export function formatDatabaseServiceLabel(serviceKey: string): string | null {
  const key = normalizeDatabaseServiceKey(serviceKey)

  switch (key) {
    case 'tablesdb':
      return 'TablesDB'
    case 'documentsdb':
      return 'DocumentsDB'
    case 'vectorsdb':
      return 'VectorsDB'
    case 'databases':
    case 'legacy':
      return 'Databases'
    default:
      return null
  }
}

export function isDatabaseUsageService(serviceKey: string): boolean {
  return getDatabaseServiceLucideIcon(serviceKey) !== null
}
