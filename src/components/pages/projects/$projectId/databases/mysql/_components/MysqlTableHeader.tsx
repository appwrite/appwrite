import { ServiceHeader, type Tab } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import {
  parseMysqlTableId,
  mysqlNav,
  type MysqlTableTab,
} from '@/lib/mysql-database-routes'
import { canShowTableSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import { useMemo } from 'react'
import { useT } from '@/lib/i18n/translate'

type MysqlTableHeaderProps = {
  projectId: string
  databaseId: string
  tableId: string
  activeTab: MysqlTableTab
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

export function MysqlTableHeader({
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
}: MysqlTableHeaderProps) {
  const t = useT()
  const { schema, table } = parseMysqlTableId(tableId)
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId ?? undefined)
  const showSecurityTab = canShowTableSecuritySettings(access, features)
  const nav = useMemo(
    () => mysqlNav({ projectId, databaseId }).table({ tableId }),
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
