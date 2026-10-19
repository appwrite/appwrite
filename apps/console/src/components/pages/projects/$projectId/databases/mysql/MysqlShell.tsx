import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useMysqlDatabase } from '@/lib/react-query/hooks'
import { useLocation, useParams } from '@tanstack/react-router'
import { AlertCircle } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { useCallback, useState, type ReactNode } from 'react'
import { TableViewResizableLayout } from '@/components/pages/projects/$projectId/databases/_components/TableViewResizableLayout'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'
import { parseMysqlShellRouteState, type MysqlDatabaseTab } from '@/lib/mysql-database-routes'
import { MysqlConnectDialogProvider } from './_components/MysqlConnectDialogContext'
import { MysqlDatabaseHeader } from './_components/MysqlDatabaseHeader'
import {
  MysqlDatabaseHeaderSlotProvider,
  type MysqlDatabaseHeaderSlotProps,
} from './_components/MysqlDatabaseHeaderSlotContext'
import { DedicatedDatabaseStatusHeaderAlert } from '../_components/DedicatedDatabaseStatusHeaderAlert'
import { DatabaseOperationsLockProvider } from '../_components/DatabaseOperationsLockContext'
import { NativeSidebarDatabaseBar } from '../_components/NativeSidebarDatabaseBar'
import { SchemaTablesSidebar } from './SchemaTablesSidebar'
import { useRedirectIfDedicatedDatabaseProvisioning } from '../_components/useRedirectIfDedicatedDatabaseProvisioning'
import { mysqlNav } from '@/lib/mysql-database-routes'
import { useT } from '@/lib/i18n/translate'

export type MysqlShellProps = {
  children: ReactNode
}

export function MysqlShell({ children }: MysqlShellProps) {
  const t = useT()
  const { projectId, databaseId, tableId } = useParams({
    strict: false,
  }) as {
    projectId: string
    databaseId: string
    tableId?: string
  }
  const { pathname } = useLocation()
  const { databaseTab, tableId: routeTableId } = parseMysqlShellRouteState({
    pathname,
    tableId,
  })

  const { database, isLoading: databaseLoading, error: databaseError } =
    useMysqlDatabase(projectId, databaseId)

  const showDesktopSidebar = useMediaMinWidth(1024)

  if (databaseLoading && !database) {
    return (
      <div className="flex flex-1 items-center justify-center py-16 text-[13px] text-muted-foreground">
        {t('Loading database...')}
      </div>
    )
  }

  if (!database) {
    return (
      <div className="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6">
        <Alert variant="destructive">
          <AlertCircle className="h-4 w-4" />
          <AlertTitle>{t('Database not found')}</AlertTitle>
          <AlertDescription className="text-[13px]">
            {databaseError
              ? getErrorMessage(databaseError)
              : t('This dedicated database could not be loaded.')}
          </AlertDescription>
        </Alert>
      </div>
    )
  }

  return (
    <MysqlConnectDialogProvider
      projectId={projectId}
      databaseId={databaseId}
    >
      <DatabaseOperationsLockProvider status={database.status}>
        <MysqlShellLayout
          projectId={projectId}
          databaseId={databaseId}
          tableId={routeTableId}
          databaseTab={databaseTab}
          database={database}
          showDesktopSidebar={showDesktopSidebar}
        >
          {children}
        </MysqlShellLayout>
      </DatabaseOperationsLockProvider>
    </MysqlConnectDialogProvider>
  )
}

type MysqlShellLayoutProps = {
  projectId: string
  databaseId: string
  tableId?: string
  databaseTab?: MysqlDatabaseTab
  database: NonNullable<ReturnType<typeof useMysqlDatabase>['database']>
  showDesktopSidebar: boolean
  children: ReactNode
}

function MysqlShellLayout({
  projectId,
  databaseId,
  tableId,
  databaseTab,
  database,
  showDesktopSidebar,
  children,
}: MysqlShellLayoutProps) {
  const selectedTableId = databaseTab ? undefined : tableId
  const sqlHome = mysqlNav({ projectId, databaseId }).sql()
  useRedirectIfDedicatedDatabaseProvisioning(
    database.status,
    sqlHome.to,
    sqlHome.params,
  )
  const [databaseHeaderSlot, setDatabaseHeaderSlotState] =
    useState<MysqlDatabaseHeaderSlotProps>({})
  const setDatabaseHeaderSlot = useCallback(
    (next: MysqlDatabaseHeaderSlotProps) => {
      setDatabaseHeaderSlotState((prev) => {
        if (
          prev.searchPlaceholder === next.searchPlaceholder &&
          prev.searchValue === next.searchValue &&
          prev.onSearchChange === next.onSearchChange &&
          prev.createLabel === next.createLabel &&
          prev.onCreate === next.onCreate &&
          prev.createDisabled === next.createDisabled &&
          prev.createDisabledTooltip === next.createDisabledTooltip &&
          prev.showRefresh === next.showRefresh &&
          prev.onRefresh === next.onRefresh &&
          prev.isRefreshing === next.isRefreshing &&
          prev.filterTrigger === next.filterTrigger
        ) {
          return prev
        }
        return next
      })
    },
    [],
  )

  const mainPanel = (
    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      {!showDesktopSidebar ? (
        <NativeSidebarDatabaseBar
          projectId={projectId}
          databaseId={databaseId}
          databaseName={database.name}
          nativeEngine="mysql"
        />
      ) : null}
      {databaseTab && databaseTab !== 'sql' ? (
        <div className="shrink-0 bg-background">
          <MysqlDatabaseHeader
            projectId={projectId}
            databaseId={databaseId}
            databaseTab={databaseTab}
            {...databaseHeaderSlot}
          />
        </div>
      ) : null}
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <MysqlDatabaseHeaderSlotProvider setSlot={setDatabaseHeaderSlot}>
          {children}
        </MysqlDatabaseHeaderSlotProvider>
      </div>
    </div>
  )

  return (
    <div className="flex h-full min-h-0 flex-1 flex-col overflow-hidden">
      <DedicatedDatabaseStatusHeaderAlert status={database.status} />
      {showDesktopSidebar ? (
        <TableViewResizableLayout
          className="min-h-0 flex-1"
          sidebar={
            <SchemaTablesSidebar
              projectId={projectId}
              databaseId={databaseId}
              databaseName={database.name}
              selectedTableId={selectedTableId}
              databaseTab={databaseTab}
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
