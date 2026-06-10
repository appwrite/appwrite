import { Loader2 } from 'lucide-react'
import { usePostgresDatabaseCredentials } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { PostgresCopyableField } from './PostgresCopyableField'

type PostgresConnectionCredentialFieldsProps = {
  projectId: string
  databaseId: string
}

export function PostgresConnectionCredentialFields({
  projectId,
  databaseId,
}: PostgresConnectionCredentialFieldsProps) {
  const { credentials, isLoading, error } = usePostgresDatabaseCredentials(
    projectId,
    databaseId,
  )

  if (isLoading && !credentials) {
    return (
      <div className="flex items-center gap-2 py-2 text-[13px] text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" />
        Loading credentials…
      </div>
    )
  }

  if (error && !credentials) {
    return (
      <p className="text-[13px] text-destructive">
        {getErrorMessage(error)}
      </p>
    )
  }

  if (!credentials) return null

  const databaseName = credentials.database || credentials.tcpDatabase

  return (
    <div className="grid gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <PostgresCopyableField label="Username" value={credentials.username} />
        <PostgresCopyableField
          label="Password"
          value={credentials.password}
          masked
        />
      </div>
      {databaseName ? (
        <PostgresCopyableField label="Database" value={databaseName} />
      ) : null}
      <PostgresCopyableField
        label="Connection string"
        value={credentials.connectionString}
      />
    </div>
  )
}
