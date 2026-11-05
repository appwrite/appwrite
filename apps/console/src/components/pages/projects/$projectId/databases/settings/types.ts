/** Database shape used by Appwrite DB settings cards (from `useProjectDatabase`). */
export type ProjectDatabaseDetail = {
  $id: string
  name: string
  enabled?: boolean
  createdAt: string
  updatedAt: string
  /** Dedicated compute slug, or null/shared when TablesDB is serverless. */
  specification?: string | null
}
