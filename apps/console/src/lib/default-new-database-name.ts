/** Default name pre-filled when creating a database in the console. */
export const DEFAULT_NEW_DATABASE_NAME = 'Production'

export type NewDatabaseTypeKey =
  | 'TablesDB'
  | 'DocumentsDB'
  | 'VectorsDB'
  | 'Postgres'
  | 'MySQL'

const NEW_DATABASE_NAME_BY_TYPE: Record<NewDatabaseTypeKey, string> = {
  TablesDB: 'Production TablesDB',
  DocumentsDB: 'Production DocumentsDB',
  VectorsDB: 'Production VectorsDB',
  Postgres: 'Production PostgreSQL',
  MySQL: 'Production MySQL',
}

/** Suggested name / placeholder for a database type in the create wizard. */
export function getNewDatabaseNameForType(
  type: NewDatabaseTypeKey | null | undefined,
): string {
  if (!type) return DEFAULT_NEW_DATABASE_NAME
  return NEW_DATABASE_NAME_BY_TYPE[type]
}

/** All type-specific default names (for detecting auto-filled values on type change). */
export function isAutoFilledNewDatabaseName(name: string): boolean {
  const trimmed = name.trim()
  if (!trimmed) return true
  if (trimmed === DEFAULT_NEW_DATABASE_NAME) return true
  return Object.values(NEW_DATABASE_NAME_BY_TYPE).includes(trimmed)
}
