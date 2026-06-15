import { Link } from '@tanstack/react-router'
import {
  Activity,
  Archive,
  BarChart3,
  Cable,
  Network,
  Plug,
  SearchCode,
  Settings,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import {
  postgresNav,
  type PostgresDatabaseTab,
} from '@/lib/postgres-database-routes'
import { canCreateDatabase } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes, useProject } from '@/lib/react-query/hooks'

type PostgresDatabaseNavProps = {
  projectId: string
  databaseId: string
  activeTab?: PostgresDatabaseTab
}

const navLinkClass = (active: boolean) =>
  cn(
    'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-[13px] font-medium transition-colors duration-150',
    active
      ? 'bg-accent text-foreground'
      : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
  )

export function PostgresDatabaseNav({
  projectId,
  databaseId,
  activeTab,
}: PostgresDatabaseNavProps) {
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const nav = postgresNav({ projectId, databaseId })

  const showSettings = canCreateDatabase(access, features)

  return (
    <div className="shrink-0 space-y-0.5 border-t border-border bg-background px-2.5 py-2">
      <Link {...nav.sql()} className={navLinkClass(activeTab === 'sql')}>
        <SearchCode className="h-3.5 w-3.5 shrink-0" />
        <span>SQL editor</span>
      </Link>
      <Link
        {...nav.visualizer()}
        className={navLinkClass(activeTab === 'visualizer')}
      >
        <Network className="h-3.5 w-3.5 shrink-0" />
        <span>Visualizer</span>
      </Link>
      {features.usageStats ? (
        <Link
          {...nav.monitor()}
          className={navLinkClass(activeTab === 'monitor')}
        >
          <Activity className="h-3.5 w-3.5 shrink-0" />
          <span>Monitor</span>
        </Link>
      ) : null}
      {features.databaseInsights ? (
        <Link
          {...nav.insights()}
          className={navLinkClass(activeTab === 'insights')}
        >
          <BarChart3 className="h-3.5 w-3.5 shrink-0" />
          <span>Insights</span>
        </Link>
      ) : null}
      {features.databaseBackups ? (
        <Link
          {...nav.backups()}
          className={navLinkClass(activeTab === 'backups')}
        >
          <Archive className="h-3.5 w-3.5 shrink-0" />
          <span>Backups</span>
        </Link>
      ) : null}
      <Link
        {...nav.connections()}
        className={navLinkClass(activeTab === 'connections')}
      >
        <Cable className="h-3.5 w-3.5 shrink-0" />
        <span>Connections</span>
      </Link>
      <Link
        {...nav.connect()}
        className={navLinkClass(activeTab === 'connect')}
      >
        <Plug className="h-3.5 w-3.5 shrink-0" />
        <span>Connect</span>
      </Link>
      {showSettings ? (
        <Link
          {...nav.settings()}
          className={navLinkClass(activeTab === 'settings')}
        >
          <Settings className="h-3.5 w-3.5 shrink-0" />
          <span>Settings</span>
        </Link>
      ) : null}
    </div>
  )
}
