import { getErrorMessage } from '@/lib/utils/error-formatting'
import { usePostgresDatabase } from '@/lib/react-query/hooks'
import type { PostgresDatabaseTab } from '@/lib/postgres-database-routes'
import { useParams } from '@tanstack/react-router'
import { AlertCircle } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import type { ReactNode } from 'react'
import { TableViewResizableLayout } from '@/components/pages/projects/$projectId/databases/_components/TableViewResizableLayout'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'
import { usePostgresSidebar } from './_components/PostgresSidebarContext'
import { PostgresConnectDialogProvider } from './_components/PostgresConnectDialogContext'
import { PostgresDatabaseHeader } from './_components/PostgresDatabaseHeader'
import { PostgresSidebarDatabaseBar } from './_components/PostgresSidebarDatabaseBar'
import { SchemaTablesSidebar } from './SchemaTablesSidebar'

export type PostgresShellProps = {
  databaseId: string
  tableId?: string
  databaseTab?: PostgresDatabaseTab
  children: ReactNode
}

export function PostgresShell({
  databaseId,
  tableId,
  databaseTab,
  children,
}: PostgresShellProps) {
  const { projectId } = useParams({ strict: false }) as { projectId: string }

  const { database, isLoading: databaseLoading, error: databaseError } =
    usePostgresDatabase(projectId, databaseId)

  const showDesktopSidebar = useMediaMinWidth(1024)

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
    <PostgresConnectDialogProvider
      projectId={projectId}
      databaseId={databaseId}
    >
      <PostgresShellLayout
        projectId={projectId}
        databaseId={databaseId}
        tableId={tableId}
        databaseTab={databaseTab}
        database={database}
        showDesktopSidebar={showDesktopSidebar}
      >
        {children}
      </PostgresShellLayout>
    </PostgresConnectDialogProvider>
  )
}

type PostgresShellLayoutProps = {
  projectId: string
  databaseId: string
  tableId?: string
  databaseTab?: PostgresDatabaseTab
  database: NonNullable<ReturnType<typeof usePostgresDatabase>['database']>
  showDesktopSidebar: boolean
  children: ReactNode
}

function PostgresShellLayout({
  projectId,
  databaseId,
  tableId,
  databaseTab,
  database,
  showDesktopSidebar,
  children,
}: PostgresShellLayoutProps) {
  const { activeTab, openTableInEditor } = usePostgresSidebar()
  const selectedTableId =
    databaseTab ? undefined : activeTab.tableId ?? tableId

  const handleOpenTable = (nextTableId: string) => {
    openTableInEditor(nextTableId)
  }

  const mainPanel = (
    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      {!showDesktopSidebar ? (
        <PostgresSidebarDatabaseBar
          projectId={projectId}
          databaseId={databaseId}
          databaseName={database.name}
          databaseSpecification={database.specification}
        />
      ) : null}
      {databaseTab && databaseTab !== 'sql' ? (
        <div className="shrink-0 bg-background">
          <PostgresDatabaseHeader
            projectId={projectId}
            databaseId={databaseId}
            databaseTab={databaseTab}
          />
        </div>
      ) : null}
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        {children}
      </div>
    </div>
  )

  return (
    <div className="flex h-full min-h-0 flex-1 flex-col overflow-hidden">
      {showDesktopSidebar ? (
        <TableViewResizableLayout
          className="min-h-0 flex-1"
          sidebar={
            <SchemaTablesSidebar
              projectId={projectId}
              databaseId={databaseId}
              databaseName={database.name}
              databaseSpecification={database.specification}
              selectedTableId={selectedTableId}
              databaseTab={databaseTab}
              onSelectTable={handleOpenTable}
            />
          }
        >
          {mainPanel}
        </TableViewResizableLayout>
      ) : (
        mainPanel
      )}
    </div>
  )
}
