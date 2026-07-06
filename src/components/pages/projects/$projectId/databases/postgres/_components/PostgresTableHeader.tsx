import { ServiceHeader, type Tab } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import {
  parsePostgresTableId,
  postgresNav,
  type PostgresTableTab,
} from '@/lib/postgres-database-routes'
import { canShowTableSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import { useMemo } from 'react'
import { useT } from '@/lib/i18n/translate'

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
  const t = useT()
  const { schema, table } = parsePostgresTableId(tableId)
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId ?? undefined)
  const showSecurityTab = canShowTableSecuritySettings(access, features)
  const nav = useMemo(
    () => postgresNav({ projectId, databaseId }).table({ tableId }),
    [projectId, databaseId, tableId],
  )

  const tabs: Tab[] = useMemo(
    () => [
      { id: 'rows', label: t('Rows'), ...nav.rows() },
      { id: 'columns', label: t('Columns'), ...nav.columns() },
      { id: 'indexes', label: t('Indexes'), ...nav.indexes() },
      ...(showSecurityTab
        ? [{ id: 'security', label: t('Security'), ...nav.security() }]
        : []),
      { id: 'settings', label: t('Settings'), ...nav.settings() },
    ],
    [nav, showSecurityTab, t],
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
