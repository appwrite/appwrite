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
        prev.filterTrigger === next.filterTrigger
      ) {
        return prev
      }
      return next
    })
  }, [])

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      <div className="shrink-0 bg-background">
        <MysqlTableHeader
          projectId={projectId}
          databaseId={databaseId}
          tableId={normalizedTableId}
          activeTab={activeTab}
          {...headerSlot}
        />
      </div>
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <MysqlTableHeaderSlotProvider setSlot={setHeaderSlot}>
          <Outlet />
        </MysqlTableHeaderSlotProvider>
      </div>
    </div>
  )
}
