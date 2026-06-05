import { useEffect, useRef, useState } from 'react'
import { Braces, CheckCircle2, Fingerprint, Layers, Loader2, Table as TableIcon, type LucideIcon } from 'lucide-react'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { cn } from '@/lib/utils'
import { getColumnIcon } from '@/lib/utils/column-icons'
import { productBentoIdle, QueryEqualFilter, Syn } from './MockSyntax'

type IconComponent = LucideIcon | typeof PostgresElephantIcon

const APPWRITE_TABS = [
  { id: 'tablesdb', label: 'TablesDB', Icon: TableIcon },
  { id: 'documentsdb', label: 'DocumentsDB', Icon: Braces },
  { id: 'vectorsdb', label: 'VectorsDB', Icon: Layers },
] as const

type AppwriteTabId = (typeof APPWRITE_TABS)[number]['id']

const TABLE_ROWS: {
  id: string
  driver: string
  code: string
  team: string
  lap: string
  revealDelayMs: number
  highlight?: boolean
}[] = [
  { id: '67f8a2…04c1', driver: 'Charles Leclerc', code: 'LEC', team: 'ferrari', lap: '70842', revealDelayMs: 220 },
  { id: '67f8b1…12a4', driver: 'Lando Norris', code: 'NOR', team: 'mclaren', lap: '71016', revealDelayMs: 360 },
  { id: '67f8c3…28b7', driver: 'Oscar Piastri', code: 'PIA', team: 'mclaren', lap: '71204', revealDelayMs: 500 },
  {
    id: '67f8d4…39c8',
    driver: 'Lewis Hamilton',
    code: 'HAM',
    team: 'ferrari',
    lap: '71388',
    revealDelayMs: 640,
    highlight: true,
  },
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
  },
  {
    id: 'mysql',
    label: 'MySQL',
    Icon: MySQLDolphinIcon,
  },
] as const

const TABLE_COLUMNS = [
  { key: 'id', label: '$id', type: 'system-id', cellAlign: 'left' as const, width: 96 },
  { key: 'driver', label: 'driver', type: 'string', cellAlign: 'left' as const, width: 128 },
  { key: 'code', label: 'code', type: 'string', cellAlign: 'left' as const, width: 56 },
  { key: 'team', label: 'team', type: 'string', cellAlign: 'left' as const },
  { key: 'lap', label: 'lap_ms', type: 'integer', cellAlign: 'right' as const },
] as const

function TablesDbColumnIcon({ type }: { type: string }) {
  const Icon = type === 'system-id' ? Fingerprint : getColumnIcon(type)

  return <Icon className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
}

const spreadsheetHeaderCellClass =
  'border-r border-border shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'
const spreadsheetLastHeaderCellClass =
  'shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'
const spreadsheetBodyCellClass = 'border-b border-r border-border'
const spreadsheetLastBodyCellClass = 'border-b border-border'

const EMPTY_TABLE_ROW_COUNT = 6

function SpreadsheetCheckboxPlaceholder() {
  return (
    <div
      className="mx-auto size-3.5 rounded-[3px] border border-border bg-background"
      aria-hidden
    />
  )
}

function SpreadsheetCheckboxCell() {
  return (
    <td
      className={cn(
        'border-b border-border px-2 py-1.5 text-center',
        'shadow-[inset_-1px_0_0_0_var(--border)]',
      )}
    >
      <SpreadsheetCheckboxPlaceholder />
    </td>
  )
}

function SpreadsheetEmptyRow() {
  return (
    <tr aria-hidden>
      <SpreadsheetCheckboxCell />
      {TABLE_COLUMNS.map((column, columnIndex) => {
        const isLast = columnIndex === TABLE_COLUMNS.length - 1

        return (
          <td
            key={column.key}
            className={cn(
              'px-3 py-1.5',
              isLast ? spreadsheetLastBodyCellClass : spreadsheetBodyCellClass,
            )}
          >
            <span className="block min-h-[18px]" />
          </td>
        )
      })}
    </tr>
  )
}

function TablesDbPanel({ playKey }: { playKey: number }) {
  const shouldAnimate = playKey > 0
  const [queryState, setQueryState] = useState<'running' | 'done'>(shouldAnimate ? 'running' : 'done')
  const [rowCount, setRowCount] = useState(shouldAnimate ? 843 : 847)

  useEffect(() => {
    if (playKey === 0) {
      setQueryState('done')
      setRowCount(847)
      return
    }

    setQueryState('running')
    setRowCount(843)

    const countTimer = window.setTimeout(() => setRowCount(847), 780)
    const doneTimer = window.setTimeout(() => setQueryState('done'), 920)

    return () => {
      window.clearTimeout(countTimer)
      window.clearTimeout(doneTimer)
    }
  }, [playKey])

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-border bg-background/70">
      <div className="border-b border-border bg-muted/5 px-3.5 py-2.5">
        <div className="flex items-start justify-between gap-3">
          <p className="min-w-0 font-mono text-[12px] text-muted-foreground">
            monaco_gp / <span className={cn('font-medium', productBentoIdle.text)}>lap_times</span>
          </p>
          <p
            className={cn(
              'shrink-0 text-[11px] tabular-nums text-muted-foreground transition-[color,transform] duration-300',
              rowCount === 847 && 'group-hover:scale-105 group-hover:text-foreground',
            )}
          >
            {rowCount} rows
          </p>
        </div>
      </div>

      <div
        className={cn(
          'flex items-center gap-2 border-b border-border/80 bg-background/60 px-3.5 py-2',
          shouldAnimate && queryState === 'running' && 'product-bento-db-query-running',
        )}
      >
        <p className="min-w-0 flex-1 truncate font-mono text-[11px]">
          <QueryEqualFilter />
        </p>
        {queryState === 'running' ? (
          <Loader2 className="size-3.5 shrink-0 animate-spin text-muted-foreground" aria-hidden />
        ) : (
          <CheckCircle2
            className={cn('size-3.5 shrink-0', productBentoIdle.emeraldIcon)}
            aria-hidden
          />
        )}
      </div>

      <div className="min-h-0 flex-1 overflow-auto">
        <table className="w-full table-fixed border-collapse">
          <colgroup>
            <col style={{ width: 40 }} />
            {TABLE_COLUMNS.map((column) => (
              <col
                key={column.key}
                style={'width' in column && column.width ? { width: column.width } : undefined}
              />
            ))}
          </colgroup>
          <thead className="sticky top-0 z-20 bg-background">
            <tr>
              <th
                className={cn(
                  'w-10 px-2 py-2 text-center',
                  'shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border),inset_-1px_0_0_0_var(--border)]',
                )}
              >
                <SpreadsheetCheckboxPlaceholder />
              </th>
              {TABLE_COLUMNS.map((column, index) => {
                const isLast = index === TABLE_COLUMNS.length - 1

                return (
                  <th
                    key={column.key}
                    className={cn(
                      'px-3 py-2 text-left',
                      isLast ? spreadsheetLastHeaderCellClass : spreadsheetHeaderCellClass,
                    )}
                  >
                    <div className="flex min-w-0 items-center gap-2">
                      <TablesDbColumnIcon type={column.type} />
                      <span
                        className={cn(
                          'min-w-0 truncate text-[12px] font-medium',
                          productBentoIdle.text,
                        )}
                      >
                        {column.label}
                      </span>
                    </div>
                  </th>
                )
              })}
            </tr>
          </thead>
          <tbody>
            {TABLE_ROWS.map((row) => (
              <tr
                key={row.id}
                className={cn(
                  shouldAnimate &&
                    row.highlight &&
                    'product-bento-db-row-highlight motion-reduce:animate-none',
                  'transition-[background-color] duration-300 hover:bg-muted/50 motion-reduce:hover:bg-transparent',
                )}
                style={
                  shouldAnimate && row.highlight
                    ? { animationDelay: `${row.revealDelayMs}ms` }
                    : undefined
                }
              >
                <SpreadsheetCheckboxCell />
                {TABLE_COLUMNS.map((column, columnIndex) => {
                  const isLast = columnIndex === TABLE_COLUMNS.length - 1
                  const value = row[column.key]
                  const isMutedValue =
                    column.key === 'team' || column.key === 'code' || column.type === 'system-id'
                  const isAccentValue = column.key === 'driver' || column.key === 'lap'

                  return (
                    <td
                      key={column.key}
                      className={cn(
                        'px-3 py-1.5',
                        isLast ? spreadsheetLastBodyCellClass : spreadsheetBodyCellClass,
                        column.cellAlign === 'right' && 'text-right',
                        column.type === 'system-id' && 'font-mono',
                      )}
                    >
                      <span
                        className={cn(
                          'block truncate text-[12px]',
                          isMutedValue && 'text-muted-foreground',
                          isAccentValue && productBentoIdle.text,
                          column.key === 'lap' && 'tabular-nums',
                          shouldAnimate &&
                            'product-bento-db-row-reveal motion-reduce:opacity-100',
                        )}
                        style={
                          shouldAnimate ? { animationDelay: `${row.revealDelayMs}ms` } : undefined
                        }
                      >
                        {value}
                      </span>
                    </td>
                  )
                })}
              </tr>
            ))}
            {Array.from({ length: EMPTY_TABLE_ROW_COUNT }, (_, index) => (
              <SpreadsheetEmptyRow key={`empty-row-${index}`} />
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function DocumentsDbPanel({ playKey }: { playKey: number }) {
  return (
    <div
      key={playKey}
      className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-border bg-background/70"
    >
      <div className="flex items-center justify-between gap-2 border-b border-border bg-muted/5 px-3.5 py-2.5">
        <div className="min-w-0 product-bento-db-reveal" style={{ animationDelay: '0ms' }}>
          <p className={cn('text-[12px] font-medium', productBentoIdle.text)}>race_briefings</p>
          <p className="truncate text-[10px] text-muted-foreground">Monaco GP strategy</p>
        </div>
        <Badge
          variant="inactive"
          className="product-bento-db-reveal h-5 shrink-0 px-1.5 text-[10px] transition-[color,background-color,border-color] duration-300 group-hover:border-green-500/30 group-hover:bg-green-500/10 group-hover:text-green-700 dark:group-hover:text-green-400"
          style={{ animationDelay: '420ms' }}
        >
          Live
        </Badge>
      </div>
      <div className="min-h-0 flex-1 overflow-hidden p-3 font-mono text-[11px] leading-relaxed sm:text-[12px]">
        <div className="product-bento-db-reveal" style={{ animationDelay: '60ms' }}>
          <Syn tone="punctuation">{'{'}</Syn>
        </div>
        <div className="product-bento-db-reveal pl-2" style={{ animationDelay: '120ms' }}>
          <Syn tone="property">&quot;event&quot;</Syn>
          <Syn tone="punctuation">: </Syn>
          <Syn tone="string">&quot;Monaco GP&quot;</Syn>
          <Syn tone="punctuation">,</Syn>
        </div>
        <div className="product-bento-db-reveal pl-2" style={{ animationDelay: '180ms' }}>
          <Syn tone="property">&quot;session&quot;</Syn>
          <Syn tone="punctuation">: </Syn>
          <Syn tone="string">&quot;Race&quot;</Syn>
          <Syn tone="punctuation">,</Syn>
        </div>
        <div className="product-bento-db-reveal pl-2" style={{ animationDelay: '260ms' }}>
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
        <div className="product-bento-db-reveal pl-2" style={{ animationDelay: '340ms' }}>
          <Syn tone="property">&quot;strategy&quot;</Syn>
          <Syn tone="punctuation">: </Syn>
          <Syn tone="string">&quot;Medium stint, pit 14-17&quot;</Syn>
        </div>
        <div className="product-bento-db-reveal" style={{ animationDelay: '400ms' }}>
          <Syn tone="punctuation">{'}'}</Syn>
        </div>
      </div>
    </div>
  )
}

function VectorsDbPanel({ playKey }: { playKey: number }) {
  return (
    <div
      key={playKey}
      className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-border bg-background/70 p-3.5"
    >
      <div
        className="product-bento-db-reveal shrink-0 rounded-md border border-border/80 bg-muted/8 px-3 py-2"
        style={{ animationDelay: '0ms' }}
      >
        <p className="text-[10px] uppercase tracking-wider text-muted-foreground">Query</p>
        <p className="mt-1 truncate font-mono text-[11px] sm:text-[12px]">
          <Syn tone="string">&quot;Monaco undercut on Medium&quot;</Syn>
        </p>
      </div>
      <div className="mt-3 min-h-0 flex-1 space-y-2">
        {VECTOR_RESULTS.map((row, index) => (
          <div
            key={`${row.title}-${playKey}`}
            className="product-bento-db-result-reveal flex items-center justify-between gap-3 rounded-md border border-border/70 bg-muted/5 px-3 py-2 motion-reduce:opacity-100"
            style={{ animationDelay: `${120 + index * 160}ms` }}
          >
            <span className={cn('min-w-0 truncate text-[11px] font-medium sm:text-[12px]', productBentoIdle.text)}>
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

function NativeDbSelectionCard({
  label,
  Icon,
}: {
  label: string
  Icon: IconComponent
}) {
  return (
    <div
      className={cn(
        'flex min-w-0 items-center gap-2.5 rounded-lg border border-border bg-background/60 px-2.5 py-2 transition-colors duration-300 sm:gap-3 sm:px-3 sm:py-2.5',
        'group-hover:bg-accent/15 motion-reduce:group-hover:bg-background/60',
      )}
    >
      <span className="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40 text-muted-foreground">
        <Icon className="size-4" aria-hidden />
      </span>
      <span
        className={cn(
          'min-w-0 flex-1 truncate text-[12px] font-medium sm:text-[13px]',
          productBentoIdle.text,
        )}
      >
        {label}
      </span>
    </div>
  )
}

function NativeDbOrSeparator() {
  return (
    <div className="flex items-center gap-2 px-1">
      <div className="h-px flex-1 bg-border" />
      <span className="text-[9px] font-medium uppercase tracking-wider text-muted-foreground">
        or
      </span>
      <div className="h-px flex-1 bg-border" />
    </div>
  )
}

function NativeDbSelectionStrip() {
  const [postgres, mysql] = NATIVE_DATABASES

  return (
    <div className="grid grid-cols-2 gap-2">
      <NativeDbSelectionCard label={postgres.label} Icon={postgres.Icon} />
      <NativeDbSelectionCard label={mysql.label} Icon={mysql.Icon} />
    </div>
  )
}

export function DatabasesProductVisual() {
  const [activeTab, setActiveTab] = useState<AppwriteTabId>('tablesdb')
  const [tablesPlayKey, setTablesPlayKey] = useState(0)
  const [documentsPlayKey, setDocumentsPlayKey] = useState(0)
  const [vectorsPlayKey, setVectorsPlayKey] = useState(0)
  const visualHoveredRef = useRef(false)

  const tabPanelClassName =
    'absolute inset-0 mt-0 flex h-full w-full min-h-0 flex-col overflow-hidden p-3.5 focus-visible:outline-none'

  const replayTablesAnimation = () => {
    setTablesPlayKey((key) => key + 1)
  }

  const handleVisualEnter = () => {
    if (visualHoveredRef.current) {
      return
    }

    visualHoveredRef.current = true

    if (activeTab === 'tablesdb') {
      replayTablesAnimation()
    }
  }

  const handleVisualLeave = () => {
    visualHoveredRef.current = false

    if (activeTab === 'tablesdb') {
      setTablesPlayKey(0)
    }
  }

  const handleTabChange = (value: string) => {
    const tab = value as AppwriteTabId
    setActiveTab(tab)

    if (tab === 'tablesdb') {
      replayTablesAnimation()
    } else if (tab === 'documentsdb') {
      setDocumentsPlayKey((key) => key + 1)
    } else if (tab === 'vectorsdb') {
      setVectorsPlayKey((key) => key + 1)
    }
  }

  return (
    <Tabs
      value={activeTab}
      onValueChange={handleTabChange}
      onMouseEnter={handleVisualEnter}
      onMouseLeave={handleVisualLeave}
      className="absolute inset-0 flex flex-col gap-0 overflow-visible"
    >
      <div className="relative min-h-0 flex-1">
        <TabsContent value="tablesdb" className={tabPanelClassName}>
          <TablesDbPanel playKey={tablesPlayKey} />
        </TabsContent>
        <TabsContent value="documentsdb" className={tabPanelClassName}>
          <DocumentsDbPanel key={documentsPlayKey} playKey={documentsPlayKey} />
        </TabsContent>
        <TabsContent value="vectorsdb" className={tabPanelClassName}>
          <VectorsDbPanel key={vectorsPlayKey} playKey={vectorsPlayKey} />
        </TabsContent>

        <div className="pointer-events-none absolute inset-x-0 bottom-0 z-50 flex justify-center px-3 sm:bottom-1">
          <TabsList className="pointer-events-auto inline-flex h-auto w-auto gap-1 rounded-lg border border-border bg-background p-1 shadow-sm">
            {APPWRITE_TABS.map((tab) => {
              const Icon = tab.Icon

              return (
                <TabsTrigger
                  key={tab.id}
                  value={tab.id}
                  className="h-auto gap-1.5 rounded-md px-2.5 py-1.5 text-[10px] text-muted-foreground transition-colors duration-300 data-[state=active]:bg-muted/60 data-[state=active]:text-muted-foreground data-[state=active]:shadow-none sm:text-[11px] group-hover:data-[state=active]:bg-muted group-hover:data-[state=active]:text-foreground [&_svg:not([class*='size-'])]:size-3"
                >
                  <Icon aria-hidden />
                  <span className="truncate">{tab.label}</span>
                </TabsTrigger>
              )
            })}
          </TabsList>
        </div>
      </div>

      <div className="relative z-10 shrink-0 bg-card/10 px-3.5 pb-3.5 pt-4">
        <NativeDbOrSeparator />
        <div className="mt-2.5">
          <NativeDbSelectionStrip />
        </div>
      </div>
    </Tabs>
  )
}
