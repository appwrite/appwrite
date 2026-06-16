import { useCallback, useMemo, useState } from 'react'
import { Outlet, useLocation, useParams } from '@tanstack/react-router'
import {
  normalizePostgresTableRouteId,
  parsePostgresTableTabFromPathname,
} from '@/lib/postgres-database-routes'
import { PostgresTableHeader } from './_components/PostgresTableHeader'
import {
  PostgresTableHeaderSlotProvider,
  type PostgresTableHeaderSlotProps,
} from './_components/PostgresTableHeaderSlotContext'

export function PostgresTableLayout() {
  const { projectId, databaseId, tableId } = useParams({
    strict: false,
  }) as {
    projectId: string
    databaseId: string
    tableId: string
  }
  const location = useLocation()
  const normalizedTableId = normalizePostgresTableRouteId(tableId)
  const activeTab = useMemo(
    () => parsePostgresTableTabFromPathname(location.pathname) ?? 'rows',
    [location.pathname],
  )

  const [headerSlot, setHeaderSlotState] = useState<PostgresTableHeaderSlotProps>({})
  const setHeaderSlot = useCallback((next: PostgresTableHeaderSlotProps) => {
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
        <PostgresTableHeader
          projectId={projectId}
          databaseId={databaseId}
          tableId={normalizedTableId}
          activeTab={activeTab}
          {...headerSlot}
        />
      </div>
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <PostgresTableHeaderSlotProvider setSlot={setHeaderSlot}>
          <Outlet />
        </PostgresTableHeaderSlotProvider>
      </div>
    </div>
  )
}
