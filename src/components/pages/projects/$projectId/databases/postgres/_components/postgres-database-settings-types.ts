import type { Models } from '@appwrite.io/console'

export type PostgresDatabaseSettingsCardProps = {
  projectId: string
  databaseId: string
  database: Models.DedicatedDatabase
  canWrite: boolean
}
