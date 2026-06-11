import type { Models } from '@appwrite.io/console'
import { AlertCircle, Users } from 'lucide-react'
import {
  usePostgresDatabase,
  usePostgresDatabaseConnections,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { PostgresCopyableField } from './_components/PostgresCopyableField'
import { PostgresConnectionCredentialFields } from './_components/PostgresConnectionCredentialFields'

type PostgresConnectionDetailsProps = {
  projectId: string
  databaseId: string
  /** Center content vertically when shown in the SQL workbench results panel. */
  centerInPanel?: boolean
}

function connectionRoleVariant(
  role: string,
): 'info' | 'success' | 'warning' {
  switch (role.toLowerCase()) {
    case 'readonly':
      return 'info'
    case 'readwrite':
      return 'success'
    default:
      return 'warning'
  }
}

export function PostgresConnectionDetails({
  projectId,
  databaseId,
  centerInPanel = false,
}: PostgresConnectionDetailsProps) {
  const { database } = usePostgresDatabase(projectId, databaseId)
  const {
    connections,
    isLoading: connectionsLoading,
    error,
  } = usePostgresDatabaseConnections(projectId, databaseId)

  const errorMessage = error ? getErrorMessage(error) : null

  if (!database) return null

  return (
    <div
      className={cn(
        'flex min-h-0 flex-1 flex-col overflow-y-auto',
        centerInPanel && 'items-center justify-center',
      )}
    >
      <div
        className={cn(
          'mx-auto w-full max-w-7xl px-4 py-4 sm:px-6',
          centerInPanel && 'my-auto shrink-0',
        )}
      >
        <div className="space-y-6">
          {errorMessage ? (
            <Alert variant="destructive">
              <AlertCircle className="h-4 w-4" />
              <AlertTitle>Failed to load connections</AlertTitle>
              <AlertDescription className="text-[13px]">
                {errorMessage}
              </AlertDescription>
            </Alert>
          ) : null}

          <div className="overflow-hidden rounded-xl border border-border bg-card/50">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Connection endpoint
              </h3>
              <p className="mt-2 text-[13px] text-muted-foreground">
                Host, port, credentials, and DSN for connecting external clients,
                ORMs, and CLI tools.
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 @container">
              <div className="flex flex-col gap-6 @[600px]:flex-row">
                <div className="shrink-0 @[600px]:w-64">
                  <p className="text-[13px] text-muted-foreground">
                    Configure clients field by field, or copy the DSN for tools
                    that accept a single connection URI.
                  </p>
                  {database.engine ? (
                    <div className="mt-4 flex flex-wrap gap-2">
                      <Badge variant="info" className="shrink-0 text-[10px]">
                        {database.engine}
                      </Badge>
                      {database.version ? (
                        <Badge variant="info" className="shrink-0 text-[10px]">
                          v{database.version}
                        </Badge>
                      ) : null}
                      {database.replicas > 0 ? (
                        <Badge variant="info" className="shrink-0 text-[10px]">
                          {database.replicas} replica
                          {database.replicas === 1 ? '' : 's'}
                        </Badge>
                      ) : null}
                    </div>
                  ) : null}
                </div>
                <div className="min-w-0 flex-1">
                  <div className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_6.5rem]">
                      <PostgresCopyableField
                        label="Host"
                        value={database.hostname}
                      />
                      <PostgresCopyableField
                        label="Port"
                        value={
                          database.connectionPort
                            ? String(database.connectionPort)
                            : ''
                        }
                      />
                    </div>
                    <PostgresConnectionCredentialFields
                      projectId={projectId}
                      databaseId={databaseId}
                    />
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div className="overflow-hidden rounded-xl border border-border bg-card/50">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Database users
              </h3>
              <p className="mt-2 text-[13px] text-muted-foreground">
                User accounts provisioned for this database instance.
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              {connections.length === 0 && !connectionsLoading ? (
                <EmptyState
                  icon={Users}
                  title="No database users yet"
                  description="User connections will appear here once they are created."
                  isEmpty
                  variant="card"
                  iconSize="md"
                />
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow className="border-b border-border hover:bg-transparent">
                      <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Username
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Database
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Role
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Created
                      </TableHead>
                      <TableHead className="w-[100px] px-4 py-3 text-right text-[12px] font-semibold uppercase tracking-wider text-muted-foreground" />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {connections.map(
                      (connection: Models.DedicatedDatabaseConnection) => (
                        <TableRow key={connection.$id}>
                          <TableCell className="px-4 py-3">
                            <span className="text-[13px] font-medium">
                              {connection.username}
                            </span>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <span className="font-mono text-[13px] text-muted-foreground">
                              {connection.database}
                            </span>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Badge
                              variant={connectionRoleVariant(connection.role)}
                              className="shrink-0 text-[10px] capitalize"
                            >
                              {connection.role || 'Unknown'}
                            </Badge>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            {connection.$createdAt ? (
                              <DateTooltip date={connection.$createdAt} />
                            ) : (
                              <span className="text-[13px] text-muted-foreground">
                                Unknown
                              </span>
                            )}
                          </TableCell>
                          <TableCell className="px-4 py-3 text-right">
                            <CopyableId
                              id={connection.$id}
                              variant="inline"
                              size="xs"
                              maxWidth={72}
                            />
                          </TableCell>
                        </TableRow>
                      ),
                    )}
                  </TableBody>
                </Table>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
