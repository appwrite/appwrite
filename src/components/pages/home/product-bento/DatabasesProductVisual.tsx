import { useEffect, useState, type ReactNode } from 'react'
import { Braces, Layers, Table as TableIcon, type LucideIcon } from 'lucide-react'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { cn } from '@/lib/utils'
import {
  MySqlIdleSql,
  MySqlTypedSql,
  PostgresIdleSql,
  PostgresTypedSql,
  Syn,
} from './MockSyntax'

type TypedSqlComponent = ({ baseDelayMs }: { baseDelayMs?: number }) => ReactNode

type IconComponent = LucideIcon | typeof PostgresElephantIcon

const APPWRITE_TABS = [
  { id: 'tablesdb', label: 'TablesDB', Icon: TableIcon },
  { id: 'documentsdb', label: 'DocumentsDB', Icon: Braces, badge: 'beta' as const },
  { id: 'vectorsdb', label: 'VectorsDB', Icon: Layers, badge: 'beta' as const },
] as const

type AppwriteTabId = (typeof APPWRITE_TABS)[number]['id']

const APPWRITE_TAB_IDS: AppwriteTabId[] = APPWRITE_TABS.map((tab) => tab.id)

const AUTO_CYCLE_MS = 3000

const TABLE_ROWS: {
  driver: string
  team: string
  lap: string
  revealDelayMs?: number
}[] = [
  { driver: 'LEC', team: 'ferrari', lap: '70842' },
  { driver: 'NOR', team: 'mclaren', lap: '71016' },
  { driver: 'PIA', team: 'mclaren', lap: '71204' },
  { driver: 'HAM', team: 'mercedes', lap: '71388', revealDelayMs: 200 },
]

const VECTOR_RESULTS = [
  {
    title: 'Monaco undercut window',
    score: '94%',
    delayMs: 140,
  },
  {
    title: 'Tyre cliff after lap 22',
    score: '91%',
    delayMs: 280,
  },
] as const

const NATIVE_DATABASES = [
  {
    id: 'postgres',
    label: 'Postgres',
    Icon: PostgresElephantIcon,
    idleSql: <PostgresIdleSql />,
    TypedSql: PostgresTypedSql,
  },
  {
    id: 'mysql',
    label: 'MySQL',
    Icon: MySQLDolphinIcon,
    idleSql: <MySqlIdleSql />,
    TypedSql: MySqlTypedSql,
  },
] as const

function TablesDbPanel() {
  return (
    <div className="flex h-full flex-col overflow-hidden rounded-lg border border-border bg-background/90">
      <div className="border-b border-border bg-muted/20 px-3.5 py-2.5">
        <div className="flex items-start justify-between gap-3">
          <p className="min-w-0 font-mono text-[12px] text-muted-foreground">
            monaco_gp / <span className="font-medium text-foreground">lap_times</span>
          </p>
          <p className="shrink-0 text-[11px] tabular-nums text-muted-foreground">847 rows</p>
        </div>
      </div>

      <div className="border-b border-border/80 bg-background/60 px-3.5 py-2">
        <p className="truncate font-mono text-[11px] text-muted-foreground">
          Query.equal(&quot;session_id&quot;, &quot;Q3_MON&quot;)
        </p>
      </div>

      <div className="min-h-0 flex-1 overflow-hidden px-3.5 py-2.5">
        <div className="grid grid-cols-3 gap-x-3 border-b border-border/80 pb-2 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
          <span>Driver</span>
          <span>Team</span>
          <span className="text-right">Lap ms</span>
        </div>
        <div className="space-y-1 pt-1.5">
          {TABLE_ROWS.map((row) => (
            <div
              key={row.driver}
              className={cn(
                'grid grid-cols-3 gap-x-3 font-mono text-[13px] leading-snug',
                row.revealDelayMs !== undefined &&
                  'opacity-0 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:opacity-100',
              )}
              style={
                row.revealDelayMs !== undefined
                  ? { transitionDelay: `${row.revealDelayMs}ms` }
                  : undefined
              }
            >
              <span className="truncate text-foreground">{row.driver}</span>
              <span className="truncate text-muted-foreground">{row.team}</span>
              <span className="truncate text-right tabular-nums text-foreground">{row.lap}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

function DocumentsDbPanel() {
  return (
    <div className="flex h-full flex-col overflow-hidden rounded-lg border border-border bg-background/90">
      <div className="flex items-center justify-between gap-2 border-b border-border bg-muted/20 px-3.5 py-2.5">
        <div className="min-w-0">
          <p className="text-[12px] font-medium text-foreground">race_briefings</p>
          <p className="truncate text-[10px] text-muted-foreground">Monaco GP strategy</p>
        </div>
        <Badge variant="success" className="h-5 shrink-0 px-1.5 text-[10px]">
          Live
        </Badge>
      </div>
      <div className="min-h-0 flex-1 overflow-hidden p-3 font-mono text-[11px] leading-relaxed sm:text-[12px]">
        <div>
          <Syn tone="punctuation">{'{'}</Syn>
        </div>
        <div className="pl-2">
          <Syn tone="property">&quot;event&quot;</Syn>
          <Syn tone="punctuation">: </Syn>
          <Syn tone="string">&quot;Monaco GP&quot;</Syn>
          <Syn tone="punctuation">,</Syn>
        </div>
        <div className="pl-2">
          <Syn tone="property">&quot;session&quot;</Syn>
          <Syn tone="punctuation">: </Syn>
          <Syn tone="string">&quot;Race&quot;</Syn>
          <Syn tone="punctuation">,</Syn>
        </div>
        <div
          className="pl-2 opacity-0 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:opacity-100"
          style={{ transitionDelay: '160ms' }}
        >
          <Syn tone="property">&quot;weather&quot;</Syn>
          <Syn tone="punctuation">: </Syn>
          <Syn tone="punctuation">{'{ '}</Syn>
          <Syn tone="property">&quot;air_c&quot;</Syn>
          <Syn tone="punctuation">: </Syn>
          <Syn tone="number">24</Syn>
          <Syn tone="punctuation">, </Syn>
          <Syn tone="property">&quot;track_c&quot;</Syn>
          <Syn tone="punctuation">: </Syn>
          <Syn tone="number">46</Syn>
          <Syn tone="punctuation">{' }'}</Syn>
          <Syn tone="punctuation">,</Syn>
        </div>
        <div
          className="pl-2 opacity-0 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:opacity-100"
          style={{ transitionDelay: '300ms' }}
        >
          <Syn tone="property">&quot;strategy&quot;</Syn>
          <Syn tone="punctuation">: </Syn>
          <Syn tone="string">&quot;Medium stint, pit 14-17&quot;</Syn>
        </div>
        <div>
          <Syn tone="punctuation">{'}'}</Syn>
        </div>
      </div>
    </div>
  )
}

function VectorsDbPanel() {
  return (
    <div className="flex h-full flex-col overflow-hidden rounded-lg border border-border bg-background/90 p-3.5">
      <div className="rounded-md border border-border/80 bg-muted/15 px-3 py-2">
        <p className="text-[10px] uppercase tracking-wider text-muted-foreground">Query</p>
        <p className="mt-1 truncate font-mono text-[11px] sm:text-[12px]">
          <Syn tone="string">&quot;Monaco undercut on Medium&quot;</Syn>
        </p>
      </div>
      <div className="mt-3 space-y-2">
        {VECTOR_RESULTS.map((row) => (
          <div
            key={row.title}
            className="flex items-center justify-between gap-3 rounded-md border border-border/70 bg-muted/10 px-3 py-2 opacity-80 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:opacity-100"
            style={{ transitionDelay: `${row.delayMs}ms` }}
          >
            <span className="min-w-0 truncate text-[11px] font-medium text-foreground sm:text-[12px]">
              {row.title}
            </span>
            <span className="shrink-0 font-mono text-[11px] tabular-nums text-muted-foreground">
              {row.score}
            </span>
          </div>
        ))}
      </div>
    </div>
  )
}

function NativeDbPanel({
  label,
  Icon,
  idleSql,
  TypedSql,
  typeDelayMs,
}: {
  label: string
  Icon: IconComponent
  idleSql: ReactNode
  TypedSql: TypedSqlComponent
  typeDelayMs: number
}) {
  return (
    <div className="flex h-full min-h-0 flex-col px-3.5 py-3">
      <div className="flex items-center gap-2">
        <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted/50 text-muted-foreground">
          <Icon className="size-4" aria-hidden />
        </span>
        <span className="text-[12px] font-medium text-foreground">{label}</span>
        <Badge variant="inactive" className="ml-auto h-5 shrink-0 px-1.5 text-[10px]">
          Soon
        </Badge>
      </div>
      <div className="relative mt-2.5 min-h-0 flex-1 overflow-hidden rounded-lg border border-border bg-background/90 p-3.5 font-mono text-[11px] leading-normal sm:text-[12px]">
        <div className="transition-opacity duration-150 group-hover:opacity-0 motion-reduce:transition-none">
          {idleSql}
        </div>
        <div className="pointer-events-none absolute inset-3.5">
          <TypedSql baseDelayMs={typeDelayMs} />
        </div>
      </div>
    </div>
  )
}

export function DatabasesProductVisual() {
  const [activeTab, setActiveTab] = useState<AppwriteTabId>('tablesdb')
  const [autoCycle, setAutoCycle] = useState(true)
  const [isHovered, setIsHovered] = useState(false)
  const [prefersReducedMotion, setPrefersReducedMotion] = useState(false)

  useEffect(() => {
    const media = window.matchMedia('(prefers-reduced-motion: reduce)')
    const update = () => setPrefersReducedMotion(media.matches)
    update()
    media.addEventListener('change', update)
    return () => media.removeEventListener('change', update)
  }, [])

  useEffect(() => {
    if (!autoCycle || !isHovered || prefersReducedMotion) return

    const intervalId = window.setInterval(() => {
      setActiveTab((current) => {
        const currentIndex = APPWRITE_TAB_IDS.indexOf(current)
        return APPWRITE_TAB_IDS[(currentIndex + 1) % APPWRITE_TAB_IDS.length]
      })
    }, AUTO_CYCLE_MS)

    return () => window.clearInterval(intervalId)
  }, [autoCycle, isHovered, prefersReducedMotion])

  const handleTabChange = (value: string) => {
    setAutoCycle(false)
    setActiveTab(value as AppwriteTabId)
  }

  return (
    <div
      className="absolute inset-0 overflow-hidden"
      onMouseEnter={() => setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <div className="flex h-full flex-col">
        <Tabs
          value={activeTab}
          onValueChange={handleTabChange}
          className="flex min-h-0 flex-[2] flex-col gap-0 border-b border-border"
        >
          <div className="shrink-0 border-b border-border bg-card/40 px-3.5 py-3">
            <TabsList className="grid h-10 w-full grid-cols-3 gap-1 rounded-lg bg-muted/50 p-1">
              {APPWRITE_TABS.map((tab) => {
                const Icon = tab.Icon

                return (
                  <TabsTrigger
                    key={tab.id}
                    value={tab.id}
                    className="gap-1.5 px-2 text-[11px] sm:text-[12px] [&_svg:not([class*='size-'])]:size-4"
                  >
                    <Icon aria-hidden />
                    <span className="truncate">{tab.label}</span>
                    {tab.badge ? (
                      <Badge variant="info" className="h-5 shrink-0 px-1.5 text-[10px]">
                        Beta
                      </Badge>
                    ) : null}
                  </TabsTrigger>
                )
              })}
            </TabsList>
          </div>

          <TabsContent
            value="tablesdb"
            className="mt-0 min-h-0 flex-1 overflow-hidden p-3.5 focus-visible:outline-none"
          >
            <TablesDbPanel />
          </TabsContent>
          <TabsContent
            value="documentsdb"
            className="mt-0 min-h-0 flex-1 overflow-hidden p-3.5 focus-visible:outline-none"
          >
            <DocumentsDbPanel />
          </TabsContent>
          <TabsContent
            value="vectorsdb"
            className="mt-0 min-h-0 flex-1 overflow-hidden p-3.5 focus-visible:outline-none"
          >
            <VectorsDbPanel />
          </TabsContent>
        </Tabs>

        <div className="grid min-h-0 flex-1 grid-cols-2 divide-x divide-border bg-card/15">
          {NATIVE_DATABASES.map((db, index) => (
            <NativeDbPanel
              key={db.id}
              label={db.label}
              Icon={db.Icon}
              idleSql={db.idleSql}
              TypedSql={db.TypedSql}
              typeDelayMs={140 + index * 60}
            />
          ))}
        </div>
      </div>
    </div>
  )
}
