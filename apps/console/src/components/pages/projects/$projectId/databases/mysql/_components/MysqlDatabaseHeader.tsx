import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import {
  MYSQL_DATABASE_TAB_LABELS,
  type MysqlDatabaseTab,
} from '@/lib/mysql-database-routes'
import { MysqlConnectionsHeaderLimit } from './MysqlConnectionsHeaderLimit'
import type { MysqlDatabaseHeaderSlotProps } from './MysqlDatabaseHeaderSlotContext'
import { useT } from '@/lib/i18n/translate'

type MysqlDatabaseHeaderProps = {
  projectId: string
  databaseId: string
  databaseTab: MysqlDatabaseTab
} & MysqlDatabaseHeaderSlotProps

export function MysqlDatabaseHeader({
  projectId,
  databaseId,
  databaseTab,
  searchPlaceholder,
  searchValue,
  onSearchChange,
  createLabel,
  onCreate,
  createDisabled,
  createDisabledTooltip,
  showRefresh,
  onRefresh,
  isRefreshing,
  filterTrigger,
}: MysqlDatabaseHeaderProps) {
  const t = useT()
  const titleLabel = MYSQL_DATABASE_TAB_LABELS[databaseTab]

  return (
    <ServiceHeader
      title={
        databaseTab === 'connections' ? (
          <span className="inline-flex min-w-0 items-baseline gap-2">
            <span className="truncate">{t(titleLabel)}</span>
            <MysqlConnectionsHeaderLimit
              projectId={projectId}
              databaseId={databaseId}
            />
          </span>
        ) : (
          t(titleLabel)
        )
      }
      fullWidthBorder
      fullWidth={
        databaseTab === 'sql' ||
        databaseTab === 'visualizer' ||
        databaseTab === 'monitor' ||
        databaseTab === 'connections' ||
        databaseTab === 'roles'
      }
      searchPlaceholder={searchPlaceholder}
      searchValue={searchValue}
      onSearchChange={onSearchChange}
      createLabel={createLabel}
      onCreate={onCreate}
      createDisabled={createDisabled}
      createDisabledTooltip={createDisabledTooltip}
      showRefresh={showRefresh}
      onRefresh={onRefresh}
      isRefreshing={isRefreshing}
      filterTrigger={filterTrigger}
      showToolbarBottomBorder={
        databaseTab === 'roles'
      }
    />
  )
}
