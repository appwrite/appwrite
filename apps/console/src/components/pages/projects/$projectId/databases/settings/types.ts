/** Database shape used by Appwrite DB settings cards (from `useProjectDatabase`). */
export type ProjectDatabaseDetail = {
  $id: string
  name: string
  enabled?: boolean
  createdAt: string
  updatedAt: string
  /** Dedicated compute slug, or null/shared when TablesDB is serverless. */
  specification?: string | null
  pitr?: boolean | null
  pitrRetentionDays?: number | null
  /** Provisioned storage in GB; null when there is no dedicated backing. */
  storage?: number | null
}
