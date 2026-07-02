import { Link } from '@tanstack/react-router'
import {
  Activity,
  Archive,
  BarChart3,
  Cable,
  KeyRound,
  Network,
  Play,
  Settings,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import {
  SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS,
  secondarySidebarNavLinkClassName,
} from '@/lib/layout/secondary-sidebar-nav'
import {
  postgresNav,
  type PostgresDatabaseTab,
} from '@/lib/postgres-database-routes'
import { canCreateDatabase } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes, useProject } from '@/lib/react-query/hooks'
import { POSTGRES_RUN_QUERY_PLAY_ICON_CLASS } from './_components/postgres-chrome'
import { usePostgresConnectDialog } from './_components/PostgresConnectDialogContext'
import { useT } from '@/lib/i18n/translate'

type PostgresDatabaseNavProps = {
  projectId: string
  databaseId: string
  activeTab?: PostgresDatabaseTab
}

const navLinkClass = (active: boolean) =>
  cn(
    secondarySidebarNavLinkClassName(active, 'transition-colors duration-150'),
    SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
  )

export function PostgresDatabaseNav({
  projectId,
  databaseId,
  activeTab,
}: PostgresDatabaseNavProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const connectDialog = usePostgresConnectDialog()
  const nav = postgresNav({ projectId, databaseId })

  const showSettings = canCreateDatabase(access, features)

  return (
    <div className="shrink-0 space-y-0.5 border-t border-border bg-background px-2.5 py-2">
      <Link {...nav.sql()} className={navLinkClass(activeTab === 'sql')}>
        <Play className={POSTGRES_RUN_QUERY_PLAY_ICON_CLASS} />
        <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('SQL editor')}</span>
      </Link>
      {connectDialog ? (
        <button
          type="button"
          className={cn(navLinkClass(false), 'cursor-pointer')}
          onClick={connectDialog.openConnect}
        >
          <KeyRound className="h-3.5 w-3.5 shrink-0" />
          <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('Credentials')}</span>
        </button>
      ) : null}
      <Link
        {...nav.visualizer()}
        className={navLinkClass(activeTab === 'visualizer')}
      >
        <Network className="h-3.5 w-3.5 shrink-0" />
        <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('Visualizer')}</span>
      </Link>
      {features.usageStats ? (
        <Link
          {...nav.monitor()}
          className={navLinkClass(activeTab === 'monitor')}
        >
          <Activity className="h-3.5 w-3.5 shrink-0" />
          <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('Monitor')}</span>
        </Link>
      ) : null}
      {features.databaseInsights ? (
        <Link
          {...nav.insights()}
          className={navLinkClass(activeTab === 'insights')}
        >
          <BarChart3 className="h-3.5 w-3.5 shrink-0" />
          <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('Insights')}</span>
        </Link>
      ) : null}
      {features.databaseBackups ? (
        <Link
          {...nav.backups()}
          className={navLinkClass(activeTab === 'backups')}
        >
          <Archive className="h-3.5 w-3.5 shrink-0" />
          <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('Backups')}</span>
        </Link>
      ) : null}
      <Link
        {...nav.connections()}
        className={navLinkClass(activeTab === 'connections')}
      >
        <Cable className="h-3.5 w-3.5 shrink-0" />
        <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('Connections')}</span>
      </Link>
      {showSettings ? (
        <Link
          {...nav.settings()}
          className={navLinkClass(activeTab === 'settings')}
        >
          <Settings className="h-3.5 w-3.5 shrink-0" />
          <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{t('Settings')}</span>
        </Link>
      ) : null}
    </div>
  )
}
