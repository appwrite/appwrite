import { useCallback, useMemo, useState } from 'react'
import { Outlet, useLocation, useParams } from '@tanstack/react-router'
import {
  normalizeMysqlTableRouteId,
  parseMysqlTableTabFromPathname,
} from '@/lib/mysql-database-routes'
import { MysqlTableHeader } from './_components/MysqlTableHeader'
import {
  MysqlTableHeaderSlotProvider,
  type MysqlTableHeaderSlotProps,
} from './_components/MysqlTableHeaderSlotContext'
import {
  DatabaseTableRowsFullscreenProvider,
  useOptionalDatabaseTableRowsFullscreen,
} from '../_components/DatabaseTableRowsFullscreenContext'

function MysqlTableLayoutContent({
  projectId,
  databaseId,
  normalizedTableId,
  activeTab,
  headerSlot,
  setHeaderSlot,
}: {
  projectId: string
  databaseId: string
  normalizedTableId: string
  activeTab: ReturnType<typeof parseMysqlTableTabFromPathname>
  headerSlot: MysqlTableHeaderSlotProps
  setHeaderSlot: (next: MysqlTableHeaderSlotProps) => void
}) {
  const rowsFullscreen =
    useOptionalDatabaseTableRowsFullscreen()?.rowsFullscreen ?? false

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      {!rowsFullscreen ? (
        <div className="shrink-0 bg-background">
          <MysqlTableHeader
            projectId={projectId}
            databaseId={databaseId}
            tableId={normalizedTableId}
            activeTab={activeTab ?? 'rows'}
            {...headerSlot}
          />
        </div>
      ) : null}
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <MysqlTableHeaderSlotProvider setSlot={setHeaderSlot}>
          <Outlet />
        </MysqlTableHeaderSlotProvider>
      </div>
    </div>
  )
}

export function MysqlTableLayout() {
  const { projectId, databaseId, tableId } = useParams({
    strict: false,
  }) as {
    projectId: string
    databaseId: string
    tableId: string
  }
  const location = useLocation()
  const normalizedTableId = normalizeMysqlTableRouteId(tableId)
  const activeTab = useMemo(
    () => parseMysqlTableTabFromPathname(location.pathname) ?? 'rows',
    [location.pathname],
  )

  const [headerSlot, setHeaderSlotState] = useState<MysqlTableHeaderSlotProps>({})
  const setHeaderSlot = useCallback((next: MysqlTableHeaderSlotProps) => {
    setHeaderSlotState((prev) => {
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
        prev.filterTrigger === next.filterTrigger &&
        prev.beforeRefreshButtons === next.beforeRefreshButtons &&
        prev.afterRefreshButtons === next.afterRefreshButtons
      ) {
        return prev
      }
      return next
    })
  }, [])

  return (
    <DatabaseTableRowsFullscreenProvider enabled={activeTab === 'rows'}>
      <MysqlTableLayoutContent
        projectId={projectId}
        databaseId={databaseId}
        normalizedTableId={normalizedTableId}
        activeTab={activeTab}
        headerSlot={headerSlot}
        setHeaderSlot={setHeaderSlot}
      />
    </DatabaseTableRowsFullscreenProvider>
  )
}
