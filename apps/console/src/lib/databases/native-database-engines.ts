import { coerceTrimmedString } from '@/lib/databases/dedicated-database-status'

export type NativeDatabaseEngine = 'postgres' | 'mysql' | 'mongo'

export const NATIVE_DATABASE_ENGINE_LABELS: Record<
  NativeDatabaseEngine,
  string
> = {
  postgres: 'PostgreSQL',
  mysql: 'MySQL',
  mongo: 'MongoDB',
}

function normalizeDatabaseEngine(engine: string | null | undefined): string {
  return coerceTrimmedString(engine).toLowerCase()
}

export function isPostgresEngine(engine: string | null | undefined): boolean {
  const normalized = normalizeDatabaseEngine(engine)
  return normalized === 'postgres' || normalized === 'postgresql'
}

export function isMysqlEngine(engine: string | null | undefined): boolean {
  const normalized = normalizeDatabaseEngine(engine)
  return normalized === 'mysql' || normalized === 'mariadb'
}

export function isMongoEngine(engine: string | null | undefined): boolean {
  const normalized = normalizeDatabaseEngine(engine)
  return normalized === 'mongodb' || normalized === 'mongo'
}

export function matchesNativeEngine(
  engine: string | null | undefined,
  nativeEngine: NativeDatabaseEngine,
): boolean {
  if (nativeEngine === 'postgres') return isPostgresEngine(engine)
  if (nativeEngine === 'mysql') return isMysqlEngine(engine)
  return isMongoEngine(engine)
}

export function getNativeDatabaseEmptyLabel(
  nativeEngine: NativeDatabaseEngine,
): string {
  return `No ${NATIVE_DATABASE_ENGINE_LABELS[nativeEngine]} databases found`
}
