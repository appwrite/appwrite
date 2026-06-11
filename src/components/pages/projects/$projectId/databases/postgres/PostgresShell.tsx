import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  usePostgresDatabase,
  usePostgresSchemas,
  usePostgresTables,
} from '@/lib/react-query/hooks'
import { postgresTableRows, type PostgresDatabaseTab } from '@/lib/postgres-database-routes'
import { useNavigate, useParams } from '@tanstack/react-router'
import { AlertCircle } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import type { ReactNode } from 'react'
import { TableViewResizableLayout } from '@/components/pages/projects/$projectId/databases/_components/TableViewResizableLayout'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'
import { usePostgresSidebar } from './_components/PostgresSidebarContext'
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
    <PostgresShellLayout
      projectId={projectId}
      databaseId={databaseId}
      tableId={tableId}
      databaseTab={databaseTab}
      database={database}
      schemas={schemas}
      tables={tables}
      sidebarLoading={sidebarLoading}
      showDesktopSidebar={showDesktopSidebar}
    >
      {children}
    </PostgresShellLayout>
  )
}

type PostgresShellLayoutProps = {
  projectId: string
  databaseId: string
  tableId?: string
  databaseTab?: PostgresDatabaseTab
  database: NonNullable<ReturnType<typeof usePostgresDatabase>['database']>
  schemas: string[]
  tables: ReturnType<typeof usePostgresTables>['tables']
  sidebarLoading: boolean
  showDesktopSidebar: boolean
  children: ReactNode
}

function PostgresShellLayout({
  projectId,
  databaseId,
  tableId,
  databaseTab,
  database,
  schemas,
  tables,
  sidebarLoading,
  showDesktopSidebar,
  children,
}: PostgresShellLayoutProps) {
  const navigate = useNavigate()
  const { activeTab, openTableTab } = usePostgresSidebar()
  const selectedTableId = databaseTab ? undefined : activeTab.tableId
  const editorActive = !databaseTab && tableId === '-'

  const handleOpenTable = (nextTableId: string) => {
    openTableTab(nextTableId)
    navigate(postgresTableRows({ projectId, databaseId, tableId: nextTableId }))
  }

  const mainPanel = (
    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      {!showDesktopSidebar ? (
        <PostgresSidebarDatabaseBar
          projectId={projectId}
          databaseId={databaseId}
          databaseName={database.name}
        />
      ) : null}
      {databaseTab ? (
        <div className="shrink-0 bg-background">
          <PostgresDatabaseHeader databaseTab={databaseTab} />
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
              schemas={schemas}
              tables={tables}
              selectedTableId={selectedTableId}
              databaseTab={databaseTab}
              editorActive={editorActive}
              isLoading={sidebarLoading}
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
