/** Database shape used by Appwrite DB settings cards (from `useProjectDatabase`). */
export type ProjectDatabaseDetail = {
  $id: string
  name: string
  /** Saved description, `''` on a server that predates the field. */
  description: string
  enabled?: boolean
  createdAt: string
  updatedAt: string
  /** Dedicated compute slug, or null/shared when TablesDB is serverless. */
  specification?: string | null
}
