import { z } from 'zod'

/** Search params for `/projects/$projectId/databases/create`. */
export const createDatabaseWizardSearchSchema = z.object({
  type: z.string().optional(),
})

export type CreateDatabaseWizardSearch = z.infer<
  typeof createDatabaseWizardSearchSchema
>

export type CreateDatabaseWizardInitialDbType =
  | 'TablesDB'
  | 'DocumentsDB'
  | 'VectorsDB'
  | 'Postgres'
  | 'MySQL'

/** URL search value for opening the wizard with PostgreSQL selected. */
export const CREATE_DATABASE_WIZARD_POSTGRES_SEARCH = {
  type: 'postgres',
} as const satisfies CreateDatabaseWizardSearch

export function parseCreateDatabaseWizardInitialDbType(
  raw: string | undefined,
): CreateDatabaseWizardInitialDbType | null {
  if (!raw) return null
  switch (raw.trim().toLowerCase()) {
    case 'postgres':
    case 'postgresql':
      return 'Postgres'
    case 'mysql':
      return 'MySQL'
    case 'tablesdb':
      return 'TablesDB'
    case 'documentsdb':
      return 'DocumentsDB'
    case 'vectorsdb':
      return 'VectorsDB'
    default:
      return null
  }
}
