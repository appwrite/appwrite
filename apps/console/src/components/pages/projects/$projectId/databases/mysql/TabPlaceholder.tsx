import {
  MYSQL_DATABASE_TAB_LABELS,
  type MysqlDatabaseTab,
} from '@/lib/mysql-database-routes'
import { useT } from '@/lib/i18n/translate'

const TAB_DESCRIPTIONS: Partial<Record<MysqlDatabaseTab, string>> = {
  visualizer:
    'Explore database schema structure from the compute API schema endpoint.',
  monitor: 'View database metrics and usage over time.',
  backups: 'Create backups, configure policies, and restore from snapshots.',
  connections:
    'View active client sessions connected to this database instance.',
  settings:
    'Update database name, compute resources, and maintenance settings.',
}

type TabPlaceholderProps = {
  tab: MysqlDatabaseTab
}

export function TabPlaceholder({ tab }: TabPlaceholderProps) {
  const t = useT()
  const title = MYSQL_DATABASE_TAB_LABELS[tab]
  const description = TAB_DESCRIPTIONS[tab]

  return (
    <div className="flex flex-1 flex-col items-center justify-center px-6 py-16 text-center">
      <h2 className="text-[15px] font-semibold text-foreground">{t(title)}</h2>
      {description ? (
        <p className="mt-2 max-w-md text-[13px] text-muted-foreground">
          {t(description)}
        </p>
      ) : null}
      <p className="mt-4 text-[12px] text-muted-foreground">
        {t('This view is not implemented yet.')}
      </p>
    </div>
  )
}
