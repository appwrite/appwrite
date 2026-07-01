export type NativeDatabaseEngine = 'postgres' | 'mysql'

export const NATIVE_DATABASE_ENGINE_LABELS: Record<
  NativeDatabaseEngine,
  string
> = {
  postgres: 'PostgreSQL',
  mysql: 'MySQL',
}

function normalizeDatabaseEngine(engine: string | undefined): string {
  return engine?.toLowerCase().trim() ?? ''
}

export function isPostgresEngine(engine: string | undefined): boolean {
  const normalized = normalizeDatabaseEngine(engine)
  return normalized === 'postgres' || normalized === 'postgresql'
}

export function isMysqlEngine(engine: string | undefined): boolean {
  const normalized = normalizeDatabaseEngine(engine)
  return normalized === 'mysql' || normalized === 'mariadb'
}

export function matchesNativeEngine(
  engine: string | undefined,
  nativeEngine: NativeDatabaseEngine,
): boolean {
  return nativeEngine === 'postgres'
    ? isPostgresEngine(engine)
    : isMysqlEngine(engine)
}

export function getNativeDatabaseEmptyLabel(
  nativeEngine: NativeDatabaseEngine,
): string {
  return `No ${NATIVE_DATABASE_ENGINE_LABELS[nativeEngine]} databases found`
}
