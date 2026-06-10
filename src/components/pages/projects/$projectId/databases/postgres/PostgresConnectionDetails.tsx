import type { Models } from '@appwrite.io/console'
import { AlertCircle, Loader2, Plug } from 'lucide-react'
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
  const { database, isLoading: databaseLoading } = usePostgresDatabase(
    projectId,
    databaseId,
  )
  const {
    connections,
    total,
    isLoading: connectionsLoading,
    isFetching,
    error,
  } = usePostgresDatabaseConnections(projectId, databaseId)

  const isLoading = databaseLoading || connectionsLoading
  const errorMessage = error ? getErrorMessage(error) : null

  if (isLoading && !database) {
    return (
      <div className="flex min-h-0 flex-1 items-center justify-center gap-2 text-[13px] text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" />
        Loading connection details…
      </div>
    )
  }

  return (
    <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto bg-muted/10">
      <div
        className={cn(
          'mx-auto w-full max-w-4xl space-y-4 p-4 sm:p-6',
          centerInPanel && 'my-auto shrink-0',
        )}
      >
        {errorMessage ? (
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertTitle>Failed to load connections</AlertTitle>
            <AlertDescription className="text-[13px]">
              {errorMessage}
            </AlertDescription>
          </Alert>
        ) : null}

        {database ? (
          <div className="overflow-hidden rounded-xl border border-border bg-card/50">
            <div className="px-6 py-4">
              <div className="flex flex-wrap items-center gap-2">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  <Plug className="h-4 w-4" />
                </div>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="text-[15px] font-semibold text-foreground">
                      Connection endpoint
                    </h3>
                  </div>
                  <p className="mt-1 text-[13px] text-muted-foreground">
                    Connect external clients, ORMs, and BI tools using these
                    credentials.
                  </p>
                </div>
              </div>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 @container">
              <div className="flex flex-col gap-6 @[600px]:flex-row">
                <div className="shrink-0 @[600px]:w-56">
                  <p className="text-[13px] text-muted-foreground">
                    Use the host and port for direct TCP connections. The
                    connection string includes authentication and SSL settings.
                  </p>
                  {database.engine ? (
                    <div className="mt-4 flex flex-wrap gap-2">
                      <Badge variant="info" className="text-[10px] shrink-0">
                        {database.engine}
                      </Badge>
                      {database.version ? (
                        <Badge variant="info" className="text-[10px] shrink-0">
                          v{database.version}
                        </Badge>
                      ) : null}
                      {database.replicas > 0 ? (
                        <Badge variant="info" className="text-[10px] shrink-0">
                          {database.replicas} replica{database.replicas === 1 ? '' : 's'}
                        </Badge>
                      ) : null}
                    </div>
                  ) : null}
                </div>
                <div className="min-w-0 flex-1">
                  <div className="grid gap-4">
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

                    <div className="border-t border-border pt-4">
                      <h4 className="text-[13px] font-semibold text-foreground">
                        Connection credentials
                      </h4>
                      <p className="mt-1 text-[12px] text-muted-foreground">
                        Use these values to authenticate external clients and
                        tools.
                      </p>
                      <div className="mt-4">
                        <PostgresConnectionCredentialFields
                          projectId={projectId}
                          databaseId={databaseId}
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        ) : null}

        <div className="overflow-hidden rounded-xl border border-border bg-card/50">
          <div className="px-6 py-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h3 className="text-[15px] font-semibold text-foreground">
                  Database users
                </h3>
                <p className="mt-1 text-[13px] text-muted-foreground">
                  User accounts provisioned for this database instance.
                </p>
              </div>
              <div className="flex items-center gap-2">
                {total > 0 ? (
                  <Badge variant="info" className="text-[10px] shrink-0">
                    {total} user{total === 1 ? '' : 's'}
                  </Badge>
                ) : null}
                {isFetching && !connectionsLoading ? (
                  <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                ) : null}
              </div>
            </div>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            {connections.length === 0 && !connectionsLoading ? (
              <div className="rounded-lg border border-dashed border-border bg-muted/20 px-4 py-10 text-center">
                <p className="text-[13px] font-medium text-foreground">
                  No database users yet
                </p>
                <p className="mt-1 text-[12px] text-muted-foreground">
                  User connections will appear here once they are created.
                </p>
              </div>
            ) : (
              <div className="overflow-hidden rounded-lg border border-border">
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
                              className="text-[10px] shrink-0 capitalize"
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
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
