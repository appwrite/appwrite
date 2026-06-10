import { Loader2, Plug } from 'lucide-react'
import { usePostgresDatabase } from '@/lib/react-query/hooks'
import { PostgresCopyableField } from './PostgresCopyableField'
import { PostgresConnectionCredentialFields } from './PostgresConnectionCredentialFields'

type PostgresTableRowsEmptyStateProps = {
  projectId: string
  databaseId: string
}

export function PostgresTableRowsEmptyState({
  projectId,
  databaseId,
}: PostgresTableRowsEmptyStateProps) {
  const { database, isLoading } = usePostgresDatabase(projectId, databaseId)

  return (
    <div className="flex h-full min-h-[12rem] flex-col items-center justify-center overflow-y-auto px-4 py-6 sm:px-6">
      <div className="w-full max-w-lg space-y-4">
        <div className="text-center">
          <p className="text-[13px] font-medium text-foreground">
            No rows found in this table.
          </p>
          <p className="mt-1 text-[12px] text-muted-foreground">
            Insert rows with SQL, or connect an external client using the details
            below.
          </p>
        </div>

        {isLoading && !database ? (
          <div className="flex items-center justify-center gap-2 py-6 text-[13px] text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            Loading connection details…
          </div>
        ) : database ? (
          <div className="overflow-hidden rounded-xl border border-border bg-card/50 text-left">
            <div className="px-4 py-3 sm:px-5">
              <div className="flex items-center gap-2">
                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  <Plug className="h-3.5 w-3.5" />
                </div>
                <h4 className="text-[13px] font-semibold text-foreground">
                  Connection endpoint
                </h4>
              </div>
            </div>
            <div className="border-t border-border" />
            <div className="space-y-4 px-4 py-4 sm:px-5">
              <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_6.5rem]">
                <PostgresCopyableField label="Host" value={database.hostname} />
                <PostgresCopyableField
                  label="Port"
                  value={
                    database.connectionPort
                      ? String(database.connectionPort)
                      : ''
                  }
                />
              </div>
            </div>

            <div className="border-t border-border" />
            <div className="px-4 py-3 sm:px-5">
              <h4 className="text-[13px] font-semibold text-foreground">
                Connection credentials
              </h4>
              <p className="mt-1 text-[12px] text-muted-foreground">
                Use these values to authenticate external clients and tools.
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-4 py-4 sm:px-5">
              <PostgresConnectionCredentialFields
                projectId={projectId}
                databaseId={databaseId}
              />
            </div>
          </div>
        ) : null}
      </div>
    </div>
  )
}
