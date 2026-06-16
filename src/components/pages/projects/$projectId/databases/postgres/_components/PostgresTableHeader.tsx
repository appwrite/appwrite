import { ServiceHeader, type Tab } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import {
  parsePostgresTableId,
  postgresNav,
  type PostgresTableTab,
} from '@/lib/postgres-database-routes'
import { useMemo } from 'react'

type PostgresTableHeaderProps = {
  projectId: string
  databaseId: string
  tableId: string
  activeTab: PostgresTableTab
  onCreate?: () => void
  createLabel?: string
  createDisabled?: boolean
  createDisabledTooltip?: string
  showRefresh?: boolean
  onRefresh?: () => void
  isRefreshing?: boolean
  searchPlaceholder?: string
  searchValue?: string
  onSearchChange?: (value: string) => void
  filterTrigger?: React.ReactNode
}

export function PostgresTableHeader({
  projectId,
  databaseId,
  tableId,
  activeTab,
  onCreate,
  createLabel,
  createDisabled,
  createDisabledTooltip,
  showRefresh,
  onRefresh,
  isRefreshing,
  searchPlaceholder,
  searchValue,
  onSearchChange,
  filterTrigger,
}: PostgresTableHeaderProps) {
  const { schema, table } = parsePostgresTableId(tableId)
  const nav = useMemo(
    () => postgresNav({ projectId, databaseId }).table({ tableId }),
    [projectId, databaseId, tableId],
  )

  const tabs: Tab[] = useMemo(
    () => [
      { id: 'rows', label: 'Rows', ...nav.rows() },
      { id: 'columns', label: 'Columns', ...nav.columns() },
      { id: 'indexes', label: 'Indexes', ...nav.indexes() },
      { id: 'settings', label: 'Settings', ...nav.settings() },
    ],
    [nav],
  )

  return (
    <ServiceHeader
      title={
        <span className="truncate">
          <span className="text-muted-foreground">{schema}.</span>
          {table}
        </span>
      }
      tabs={tabs}
      activeTab={activeTab}
      fullWidthBorder
      fullWidth
      createLabel={createLabel}
      onCreate={onCreate}
      createDisabled={createDisabled}
      createDisabledTooltip={createDisabledTooltip}
      showRefresh={showRefresh}
      onRefresh={onRefresh}
      isRefreshing={isRefreshing}
      searchPlaceholder={searchPlaceholder}
      searchValue={searchValue}
      onSearchChange={onSearchChange}
      filterTrigger={filterTrigger}
    />
  )
}
