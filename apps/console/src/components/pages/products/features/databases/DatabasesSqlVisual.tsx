import { Puzzle } from 'lucide-react'
import type { ComponentType } from 'react'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import {
  ArtLiveDot,
  ArtPanel,
  ArtToken as T,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const ENGINES: {
  id: string
  name: string
  version: string
  description: string
  icon: ComponentType<{ className?: string }>
}[] = [
  {
    id: 'postgresql',
    name: 'PostgreSQL',
    version: '17',
    description: 'Extensions, roles, SQL editor',
    icon: PostgresElephantIcon,
  },
  {
    id: 'mysql',
    name: 'MySQL',
    version: '8.4',
    description: 'Connections, schemas, and backups',
    icon: MySQLDolphinIcon,
  },
]

const EXTENSIONS = ['pgvector', 'PostGIS', 'pg_trgm'] as const


function Prompt() {
  return <span className="text-muted-foreground">app=&gt; </span>
}

function TerminalSession() {
  return (
    <pre dir="ltr" className="overflow-hidden whitespace-pre text-start font-mono text-[10.5px] leading-[1.75] sm:text-[11.5px]">
      <code>
        <span className="text-muted-foreground">$ </span>
        <T tone="function">psql</T> <T tone="string">&quot;$DATABASE_URL&quot;</T>
        {'\n'}
        <span className="text-muted-foreground">SSL connection (protocol: TLSv1.3)</span>
        {'\n'}
        <Prompt />
        <T tone="sqlKeyword">CREATE EXTENSION</T> <T tone="sqlFunction">postgis</T>;{'\n'}
        <span className="text-muted-foreground">CREATE EXTENSION</span>
        {'\n'}
        <Prompt />
        <T tone="sqlKeyword">SELECT</T> <T tone="sqlFunction">count</T>(*) <T tone="sqlKeyword">FROM</T>{' '}
        <T tone="sqlTable">stores</T>
        {'\n'}
        <span className="text-muted-foreground">app-&gt; </span>
        {'  '}
        <T tone="sqlKeyword">WHERE</T> <T tone="sqlFunction">ST_DWithin</T>(geom, $1, <T tone="number">5000</T>);
        {'\n'}
        <span className="text-muted-foreground">{' count\n-------\n    42\n(1 row)'}</span>
        {'\n'}
        <Prompt />
        <span
          className="inline-block h-3.5 w-1.5 translate-y-0.5 animate-[ai-mock-cursor-blink_1s_step-end_infinite] bg-[var(--tone-ink)] motion-reduce:animate-none"
          aria-hidden
        />
      </code>
    </pre>
  )
}

export function DatabasesSqlVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-2">
      <div className="relative z-[2] mb-3 flex justify-between gap-2 sm:px-4">
        {ENGINES.map((engine, index) => {
          const Icon = engine.icon
          return (
            <ArtPanel
              key={engine.id}
              className={cn('min-w-0', index === 0 ? 'sm:ms-2' : 'sm:-mb-2 sm:mt-2')}
              innerClassName="flex items-center gap-2.5 px-3 py-2.5"
              delayMs={300 + index * 150}
              float
              floatDelayMs={index * 700}
            >
              <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40">
                <Icon className="size-4 text-foreground" aria-hidden />
              </span>
              <div className="min-w-0">
                <p className="flex items-center gap-1.5 text-[12px] font-semibold text-foreground">
                  {engine.name}
                  <span dir="ltr" className="hidden font-mono text-[10px] font-normal text-muted-foreground sm:inline">
                    v{engine.version}
                  </span>
                </p>
                <p className="hidden truncate text-[10px] text-muted-foreground sm:block">{t(engine.description)}</p>
              </div>
              <span className="hidden items-center gap-1 ps-1 text-[10px] font-medium text-emerald-600 sm:inline-flex dark:text-emerald-400">
                <span className="size-1.5 rounded-full bg-emerald-500" aria-hidden />
                {t('Ready')}
              </span>
            </ArtPanel>
          )
        })}
      </div>

      <ArtWindow
        className="product-hero-rise relative z-[1]"
        style={riseStyle(60)}
        title={<span dir="ltr">psql · orders-prod</span>}
        trailing={
          <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
            <ArtLiveDot />
            {t('Connected')}
          </span>
        }
        bodyClassName="px-4 pb-8 pt-3.5"
      >
        <TerminalSession />
      </ArtWindow>

      <div className="relative z-[2] -mt-5 flex flex-wrap items-start justify-between gap-2 px-2 sm:px-6">
        <ArtPanel innerClassName="px-3 py-2.5" delayMs={900} float floatDelayMs={400}>
          <p className="flex items-center gap-1.5 text-[10px] font-medium text-muted-foreground">
            <Puzzle className="size-3 text-[var(--tone-ink)]" aria-hidden />
            {t('Extensions')}
          </p>
          <div dir="ltr" className="mt-1.5 flex flex-wrap gap-1">
            {EXTENSIONS.map((extension, index) => (
              <span
                key={extension}
                className={cn(
                  'product-hero-rise rounded-md px-1.5 py-0.5 font-mono text-[10px]',
                  index === 0
                    ? 'bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]'
                    : 'bg-[rgb(var(--tone2-rgb)/0.18)] text-foreground',
                )}
                style={riseStyle(1100 + index * 120)}
              >
                {extension}
              </span>
            ))}
          </div>
        </ArtPanel>

        <ArtPanel className="hidden sm:block" innerClassName="space-y-1 px-3 py-2.5" delayMs={1150} float floatDelayMs={1200}>
          {[
            { label: t('Host'), value: 'postgres.appwrite.cloud', code: true },
            { label: t('Port'), value: '5432', code: true },
            { label: 'SSL', value: t('Required'), code: false },
          ].map((row) => (
            <div key={row.label} className="flex items-center justify-between gap-4 text-[10px]">
              <span className="text-muted-foreground">{row.label}</span>
              {row.code ? (
                <span dir="ltr" className="font-mono text-foreground">
                  {row.value}
                </span>
              ) : (
                <span className="font-medium text-foreground">{row.value}</span>
              )}
            </div>
          ))}
        </ArtPanel>
      </div>
    </div>
  )
}
