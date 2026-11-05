import { Braces, DatabaseBackup, Layers, Play, Table as TableIcon } from 'lucide-react'
import type { ComponentType } from 'react'
import { syntax } from '@/components/pages/home/product-bento/MockSyntax'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import {
  ArtChip,
  ArtLiveDot,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const DATABASES: { name: string; engine: string; icon: ComponentType<{ className?: string }> }[] = [
  { name: 'orders-prod', engine: 'PostgreSQL', icon: PostgresElephantIcon },
  { name: 'app', engine: 'TablesDB', icon: TableIcon },
  { name: 'catalog', engine: 'DocumentsDB', icon: Braces },
  { name: 'embeddings', engine: 'VectorsDB', icon: Layers },
  { name: 'legacy', engine: 'MySQL', icon: MySQLDolphinIcon },
]

const RESULTS = [
  { email: 'paige@acme.io', total: '$1,240.00', status: 'paid' },
  { email: 'walter@acme.io', total: '$980.50', status: 'paid' },
  { email: 'happy@acme.io', total: '$642.10', status: 'pending' },
  { email: 'toby@acme.io', total: '$415.00', status: 'refunded' },
] as const

const STATUS_CLASS = {
  paid: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
  pending: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
  refunded: 'bg-muted text-muted-foreground',
} as const

function SqlQuery() {
  return (
    <pre dir="ltr" className="overflow-hidden whitespace-pre font-mono text-[11.5px] leading-[1.7] sm:text-[12px]">
      <code>
        <span className={syntax.sqlKeyword}>SELECT</span> <span className={syntax.sqlAlias}>u</span>.email,{' '}
        <span className={syntax.sqlAlias}>o</span>.total, <span className={syntax.sqlAlias}>o</span>.status{'\n'}
        <span className={syntax.sqlKeyword}>FROM</span> <span className={syntax.sqlTable}>orders</span>{' '}
        <span className={syntax.sqlAlias}>o</span>{'\n'}
        <span className={syntax.sqlKeyword}>JOIN</span> <span className={syntax.sqlTable}>users</span>{' '}
        <span className={syntax.sqlAlias}>u</span> <span className={syntax.sqlKeyword}>ON</span>{' '}
        <span className={syntax.sqlAlias}>u</span>.id = <span className={syntax.sqlAlias}>o</span>.user_id{'\n'}
        <span className={syntax.sqlKeyword}>WHERE</span> <span className={syntax.sqlAlias}>o</span>.created_at {'>'}{' '}
        <span className={syntax.sqlFunction}>now</span>() - <span className={syntax.sqlKeyword}>interval</span>{' '}
        <span className={syntax.string}>'1 day'</span>
        {'\n'}
        <span className={syntax.sqlKeyword}>ORDER BY</span> <span className={syntax.sqlAlias}>o</span>.total{' '}
        <span className={syntax.sqlKeyword}>DESC LIMIT</span> <span className={syntax.number}>4</span>;
      </code>
    </pre>
  )
}

export function DatabasesHeroArt() {
  const t = useT()

  return (
    <div className="relative px-2 sm:px-8">
      <ArtWindow
        className="product-hero-rise text-start"
        style={riseStyle(80)}
        bodyClassName="p-0"
        title={<span dir="ltr">acme / orders-prod</span>}
        trailing={
          <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
            <ArtLiveDot />
            {t('Connected')}
          </span>
        }
      >
        <div className="grid sm:grid-cols-[200px_minmax(0,1fr)]">
          <aside className="hidden border-e border-border bg-muted/20 p-3 sm:block">
            <p className="px-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Databases')}
            </p>
            <ul className="mt-2 space-y-0.5">
              {DATABASES.map((database, index) => {
                const Icon = database.icon
                return (
                  <li
                    key={database.name}
                    className={cn(
                      'flex items-center gap-2 rounded-md px-2 py-1.5',
                      index === 0 ? 'bg-background shadow-sm dark:bg-muted/50' : 'opacity-80',
                    )}
                  >
                    <Icon
                      className={cn(
                        'size-3.5 shrink-0',
                        index === 0 ? 'text-[var(--tone-ink)]' : 'text-muted-foreground',
                      )}
                    />
                    <div className="min-w-0">
                      <p className="truncate text-[12px] font-medium text-foreground">{database.name}</p>
                      <p className="truncate text-[10px] text-muted-foreground">{database.engine}</p>
                    </div>
                  </li>
                )
              })}
            </ul>
          </aside>

          <div className="min-w-0">
            <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-2">
              <p className="text-[11px] font-medium text-muted-foreground">{t('Query')}</p>
              <div className="flex items-center gap-2">
                <span dir="ltr" className="font-mono text-[11px] text-muted-foreground">12 ms</span>
                <span className="flex h-6 items-center gap-1 rounded-md bg-foreground px-2 text-[11px] font-medium text-background">
                  <Play className="size-3 fill-current" aria-hidden />
                  {t('Run')}
                </span>
              </div>
            </div>
            <div className="border-b border-border px-4 py-3">
              <SqlQuery />
            </div>
            <div className="overflow-x-auto">
              <table dir="ltr" className="w-full min-w-[420px] text-start text-[12px]">
                <thead>
                  <tr className="border-b border-border text-[10px] uppercase tracking-wider text-muted-foreground">
                    <th className="px-4 py-2 text-start font-semibold">email</th>
                    <th className="px-4 py-2 text-start font-semibold">total</th>
                    <th className="px-4 py-2 text-start font-semibold">status</th>
                  </tr>
                </thead>
                <tbody>
                  {RESULTS.map((row, index) => (
                    <tr
                      key={row.email}
                      className="product-hero-rise border-b border-border/60 last:border-b-0"
                      style={riseStyle(500 + index * 120)}
                    >
                      <td className="px-4 py-2 text-foreground">{row.email}</td>
                      <td className="px-4 py-2 font-mono tabular-nums text-foreground">{row.total}</td>
                      <td className="px-4 py-2">
                        <span className={cn('rounded px-1.5 py-0.5 text-[10px] font-medium', STATUS_CLASS[row.status])}>
                          {row.status}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </ArtWindow>

      <ArtChip className="-top-5 end-0 hidden sm:block" delayMs={900}>
        <div className="flex items-center gap-2">
          <span className="flex size-7 items-center justify-center rounded-md bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]">
            <Layers className="size-3.5" aria-hidden />
          </span>
          <div dir="ltr" className="text-start">
            <p className="text-[11px] font-medium text-foreground">pgvector</p>
            <p className="font-mono text-[10px] text-muted-foreground">cosine 0.92</p>
          </div>
        </div>
      </ArtChip>

      <ArtChip className="-bottom-5 start-0 hidden sm:block" delayMs={1200} floatDelayMs={1200}>
        <div className="flex items-center gap-2">
          <span className="flex size-7 items-center justify-center rounded-md bg-[rgb(var(--tone2-rgb)/0.18)] text-foreground">
            <DatabaseBackup className="size-3.5" aria-hidden />
          </span>
          <div className="text-start">
            <p className="text-[11px] font-medium text-foreground">{t('Backups')}</p>
            <p dir="ltr" className="font-mono text-[10px] text-muted-foreground">PITR · 02:00 UTC</p>
          </div>
        </div>
      </ArtChip>
    </div>
  )
}
