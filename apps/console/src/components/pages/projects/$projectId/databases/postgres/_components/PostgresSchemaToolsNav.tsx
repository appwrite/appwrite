import { Link } from '@tanstack/react-router'
import { ListOrdered, Network, Play } from 'lucide-react'
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
import { POSTGRES_RUN_QUERY_PLAY_ICON_CLASS } from './postgres-chrome'
import { useT } from '@/lib/i18n/translate'

type PostgresSchemaToolsNavProps = {
  projectId: string
  databaseId: string
  activeTab?: PostgresDatabaseTab
  className?: string
}

function schemaToolLinkClass(active: boolean) {
  return cn(
    secondarySidebarNavLinkClassName(active, 'transition-colors duration-150'),
    SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
    'gap-1.5 text-[12px]',
  )
}

export function PostgresSchemaToolsNav({
  projectId,
  databaseId,
  activeTab,
  className,
}: PostgresSchemaToolsNavProps) {
  const t = useT()
  const nav = postgresNav({ projectId, databaseId })

  const items = [
    {
      key: 'sql',
      link: nav.sql(),
      active: activeTab === 'sql',
      icon: Play,
      iconClassName: POSTGRES_RUN_QUERY_PLAY_ICON_CLASS,
      label: t('SQL editor'),
    },
    {
      key: 'visualizer',
      link: nav.visualizer(),
      active: activeTab === 'visualizer',
      icon: Network,
      iconClassName: 'h-3.5 w-3.5 shrink-0',
      label: t('Visualizer'),
    },
    {
      key: 'enums',
      link: nav.enums(),
      active: activeTab === 'enums',
      icon: ListOrdered,
      iconClassName: 'h-3.5 w-3.5 shrink-0',
      label: t('Enums'),
    },
  ] as const

  return (
    <ul
      className={cn('m-0 flex w-full list-none flex-col gap-1 p-0', className)}
      aria-label={t('Schema tools')}
    >
      {items.map(({ key, link, active, icon: Icon, iconClassName, label }) => (
        <li key={key} className="w-full min-w-0">
          <Link {...link} className={schemaToolLinkClass(active)}>
            <Icon className={iconClassName} />
            <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{label}</span>
          </Link>
        </li>
      ))}
    </ul>
  )
}
