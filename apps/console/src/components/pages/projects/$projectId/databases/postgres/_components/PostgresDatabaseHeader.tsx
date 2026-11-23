import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import {
  POSTGRES_DATABASE_TAB_LABELS,
  type PostgresDatabaseTab,
} from '@/lib/postgres-database-routes'
import { PostgresConnectionsHeaderLimit } from './PostgresConnectionsHeaderLimit'
import type { PostgresDatabaseHeaderSlotProps } from './PostgresDatabaseHeaderSlotContext'
import { useT } from '@/lib/i18n/translate'

type PostgresDatabaseHeaderProps = {
  projectId: string
  databaseId: string
  databaseTab: PostgresDatabaseTab
} & PostgresDatabaseHeaderSlotProps

export function PostgresDatabaseHeader({
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
}: PostgresDatabaseHeaderProps) {
  const t = useT()
  const titleLabel = POSTGRES_DATABASE_TAB_LABELS[databaseTab]

  return (
    <ServiceHeader
      title={
        databaseTab === 'connections' ? (
          <span className="inline-flex min-w-0 items-baseline gap-2">
            <span className="truncate">{t(titleLabel)}</span>
            <PostgresConnectionsHeaderLimit
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
        databaseTab === 'enums' ||
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
        databaseTab === 'roles' || databaseTab === 'enums'
      }
    />
  )
}
