import {
  Activity,
  Archive,
  Cable,
  KeyRound,
  Settings,
  Users,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import {
  SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS,
  secondarySidebarNavLinkClassName,
} from '@/lib/layout/secondary-sidebar-nav'
import {
  mysqlNav,
  type MysqlDatabaseTab,
} from '@/lib/mysql-database-routes'
import { canCreateDatabase } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  useMysqlDatabase,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import {
  DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE,
  isDedicatedDatabaseProvisioning,
} from '@/lib/databases/dedicated-database-status'
import { useMysqlConnectDialog } from './_components/MysqlConnectDialogContext'
import { DatabaseSidebarComputeSpec } from '../_components/DatabaseSidebarComputeSpec'
import { DatabaseSidebarNavItem } from '../_components/DatabaseSidebarNavItem'
import { useT } from '@/lib/i18n/translate'

type MysqlDatabaseNavProps = {
  projectId: string
  databaseId: string
  activeTab?: MysqlDatabaseTab
}

const navLinkClass = (active: boolean) =>
  cn(
    secondarySidebarNavLinkClassName(active, 'transition-colors duration-150'),
    SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
  )

export function MysqlDatabaseNav({
  projectId,
  databaseId,
  activeTab,
}: MysqlDatabaseNavProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const { database } = useMysqlDatabase(projectId, databaseId)
  const connectDialog = useMysqlConnectDialog()
  const nav = mysqlNav({ projectId, databaseId })
  const provisioning = isDedicatedDatabaseProvisioning(database?.status)

  const showSettings = canCreateDatabase(access, features)

  return (
    <div className="flex shrink-0 flex-col border-t border-border bg-background px-2.5 pt-2 pb-2 has-[*[data-sidebar-spec]]:gap-2 has-[*[data-sidebar-spec]]:pb-0">
      <div className="space-y-0.5">
      {connectDialog ? (
        <DatabaseSidebarNavItem
          disabled={provisioning}
          disabledTooltip={DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE}
          className={cn(navLinkClass(false), !provisioning && 'cursor-pointer')}
          onClick={connectDialog.openConnect}
        >
          <KeyRound className="h-3.5 w-3.5 shrink-0" />
          <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('Credentials')}</span>
        </DatabaseSidebarNavItem>
      ) : null}
      {features.usageStats ? (
        <DatabaseSidebarNavItem
          disabled={provisioning}
          disabledTooltip={DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE}
          className={navLinkClass(activeTab === 'monitor')}
          {...nav.monitor()}
        >
          <Activity className="h-3.5 w-3.5 shrink-0" />
          <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('Monitor')}</span>
        </DatabaseSidebarNavItem>
      ) : null}
      <DatabaseSidebarNavItem
        disabled={provisioning}
        disabledTooltip={DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE}
        className={navLinkClass(activeTab === 'connections')}
        {...nav.connections()}
      >
        <Cable className="h-3.5 w-3.5 shrink-0" />
        <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('Connections')}</span>
      </DatabaseSidebarNavItem>
      <DatabaseSidebarNavItem
        disabled={provisioning}
        disabledTooltip={DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE}
        className={navLinkClass(activeTab === 'roles')}
        {...nav.roles()}
      >
        <Users className="h-3.5 w-3.5 shrink-0" />
        <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('Roles')}</span>
      </DatabaseSidebarNavItem>
      {features.databaseBackups ? (
        <DatabaseSidebarNavItem
          disabled={provisioning}
          disabledTooltip={DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE}
          className={navLinkClass(activeTab === 'backups')}
          {...nav.backups()}
        >
          <Archive className="h-3.5 w-3.5 shrink-0" />
          <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('Backups')}</span>
        </DatabaseSidebarNavItem>
      ) : null}
      {showSettings ? (
        <DatabaseSidebarNavItem
          disabled={provisioning}
          disabledTooltip={DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE}
          className={navLinkClass(activeTab === 'settings')}
          {...nav.settings()}
        >
          <Settings className="h-3.5 w-3.5 shrink-0" />
          <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('Settings')}</span>
        </DatabaseSidebarNavItem>
      ) : null}
      </div>
      <DatabaseSidebarComputeSpec
        projectId={projectId}
        databaseId={databaseId}
        mode="mysql"
        variant="footer"
      />
    </div>
  )
}
