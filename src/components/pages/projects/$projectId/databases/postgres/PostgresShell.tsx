import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  usePostgresDatabase,
  usePostgresSchemas,
  usePostgresTables,
} from '@/lib/react-query/hooks'
import {
  POSTGRES_DATABASE_TAB_LABELS,
  postgresTableRows,
  type PostgresDatabaseTab,
} from '@/lib/postgres-database-routes'
import { Link, useNavigate, useParams } from '@tanstack/react-router'
import { ArrowLeft, AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import type { ReactNode } from 'react'
import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { TableViewResizableLayout } from '@/components/pages/projects/$projectId/databases/_components/TableViewResizableLayout'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'
import { SchemaTablesSidebar } from './SchemaTablesSidebar'

export type PostgresShellProps = {
  databaseId: string
  tableId?: string
  databaseTab?: PostgresDatabaseTab
  children: ReactNode
}

function dedicatedStatusVariant(
  status: string,
): 'success' | 'warning' | 'error' | 'info' {
  switch (status.toLowerCase()) {
    case 'ready':
      return 'success'
    case 'provisioning':
    case 'restoring':
    case 'scaling':
      return 'info'
    case 'inactive':
    case 'paused':
      return 'warning'
    case 'failed':
    case 'deleted':
      return 'error'
    default:
      return 'info'
  }
}

export function PostgresShell({
  databaseId,
  tableId,
  databaseTab,
  children,
}: PostgresShellProps) {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const navigate = useNavigate()

  const { database, isLoading: databaseLoading, error: databaseError } =
    usePostgresDatabase(projectId, databaseId)
  const {
    schemas,
    isLoading: schemasLoading,
    isFetching: schemasFetching,
  } = usePostgresSchemas(projectId, databaseId)
  const {
    tables,
    isLoading: tablesLoading,
    isFetching: tablesFetching,
  } = usePostgresTables(projectId, databaseId)

  const sidebarLoading =
    (schemasLoading || schemasFetching || tablesLoading || tablesFetching) &&
    schemas.length === 0 &&
    tables.length === 0

  const showDesktopSidebar = useMediaMinWidth(1024)

  const handleOpenTable = (nextTableId: string) => {
    navigate(postgresTableRows({ projectId, databaseId, tableId: nextTableId }))
  }

  if (databaseLoading && !database) {
    return (
      <div className="flex flex-1 items-center justify-center py-16 text-[13px] text-muted-foreground">
        Loading database...
      </div>
    )
  }

  if (!database) {
    return (
      <div className="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6">
        <Alert variant="destructive">
          <AlertCircle className="h-4 w-4" />
          <AlertTitle>Database not found</AlertTitle>
          <AlertDescription className="text-[13px]">
            {databaseError
              ? getErrorMessage(databaseError)
              : 'This dedicated database could not be loaded.'}
          </AlertDescription>
        </Alert>
      </div>
    )
  }

  return (
    <div className="flex h-full min-h-0 flex-1 flex-col overflow-hidden">
      <ServiceHeader
        fullWidthBorder
        fullWidth
        title={
          <div className="flex min-w-0 items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              asChild
              className="h-7 w-7 shrink-0 p-0"
              aria-label="Back to databases"
            >
              <Link to="/projects/$projectId/databases" params={{ projectId }}>
                <ArrowLeft className="h-4 w-4" />
              </Link>
            </Button>
            <span className="min-w-0 truncate">
              {databaseTab
                ? POSTGRES_DATABASE_TAB_LABELS[databaseTab]
                : database.name}
            </span>
            {!databaseTab ? (
              <>
                <CopyableId id={database.$id} size="xs" className="shrink-0" />
                <Badge
                  variant={dedicatedStatusVariant(database.status)}
                  className="text-[10px] shrink-0"
                >
                  {database.status}
                </Badge>
                <Badge variant="info" className="text-[10px] shrink-0">
                  PostgreSQL
                </Badge>
              </>
            ) : null}
          </div>
        }
      />

      {showDesktopSidebar ? (
        <TableViewResizableLayout
          className="min-h-0 flex-1"
          sidebar={
            <SchemaTablesSidebar
              projectId={projectId}
              databaseId={databaseId}
              schemas={schemas}
              tables={tables}
              selectedTableId={databaseTab ? undefined : tableId}
              databaseTab={databaseTab}
              isLoading={sidebarLoading}
              onSelectTable={handleOpenTable}
            />
          }
        >
          <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            {children}
          </div>
        </TableViewResizableLayout>
      ) : (
        <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
          {children}
        </div>
      )}
    </div>
  )
}
