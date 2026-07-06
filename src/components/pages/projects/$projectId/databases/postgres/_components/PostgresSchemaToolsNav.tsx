import { Link } from '@tanstack/react-router'
import { ListOrdered, Network, Play } from 'lucide-react'
import { cn } from '@/lib/utils'
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
    'flex w-full items-center gap-1.5 rounded-md border border-border px-2.5 py-1.5 text-[12px] font-medium transition-colors',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
    active
      ? 'border-border bg-accent text-foreground'
      : 'bg-background text-muted-foreground hover:bg-accent/50 hover:text-foreground',
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
            <span className="min-w-0 truncate">{label}</span>
          </Link>
        </li>
      ))}
    </ul>
  )
}
