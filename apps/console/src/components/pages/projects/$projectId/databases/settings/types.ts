/**
 * Database shape used by Appwrite DB settings cards (from `useProjectDatabase`).
 *
 * No `description`: the hook builds one at runtime but casts it away, so a card
 * that wants the saved value calls `readDescription` on the object instead of
 * reading a declared field. See `buildProjectDatabaseDetail`.
 */
export type ProjectDatabaseDetail = {
  $id: string
  name: string
  enabled?: boolean
  createdAt: string
  updatedAt: string
  /** Dedicated compute slug, or null/shared when TablesDB is serverless. */
  specification?: string | null
}
