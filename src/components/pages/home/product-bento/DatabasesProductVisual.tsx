import { useEffect, useRef, useState } from 'react'
import { Braces, Check, CheckCircle2, Layers, Loader2, Table as TableIcon, type LucideIcon } from 'lucide-react'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { getColumnIcon } from '@/lib/utils/column-icons'
import { productBentoContainer, productBentoIdle, QueryEqualFilter, Syn, VectorsDbSearchSnippet } from './MockSyntax'

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
  position: string
  lap: string
  revealDelayMs: number
  highlight?: boolean
}[] = [
  { id: '67f8a2…04c1', driver: 'Charles Leclerc', code: 'LEC', team: 'ferrari', position: '1', lap: '70842', revealDelayMs: 220 },
  { id: '67f8b1…12a4', driver: 'Lando Norris', code: 'NOR', team: 'mclaren', position: '2', lap: '71016', revealDelayMs: 360 },
  { id: '67f8c3…28b7', driver: 'Oscar Piastri', code: 'PIA', team: 'mclaren', position: '3', lap: '71204', revealDelayMs: 500 },
  {
    id: '67f8d4…39c8',
    driver: 'Lewis Hamilton',
    code: 'HAM',
    team: 'ferrari',
    position: '4',
    lap: '71388',
    revealDelayMs: 640,
    highlight: true,
  },
]

const VECTOR_RESULTS = [
  {
    title: 'Monaco undercut window',
    snippet: 'Pit lap 16 while rival stays out on Medium to gain track position.',
    source: 'briefing_q3.md',
    score: 0.94,
    delayMs: 140,
  },
  {
    title: 'Tyre cliff after lap 22',
    snippet: 'Medium degradation accelerates once surface temps drop in the tunnel.',
    source: 'telemetry_lap22.json',
    score: 0.91,
    delayMs: 280,
  },
  {
    title: 'Safety car restart gap',
    snippet: 'Leave 1.2s to leader at line to avoid DRS train into Turn 1.',
    source: 'race_control_notes',
    score: 0.87,
    delayMs: 420,
  },
] as const

const VECTOR_FILTERS = ['session: Monaco GP', 'type: strategy'] as const

const NATIVE_DATABASES = [
  {
    id: 'postgres',
    label: 'PostgreSQL',
    Icon: PostgresElephantIcon,
  },
  {
    id: 'mysql',
    label: 'MySQL',
    Icon: MySQLDolphinIcon,
  },
] as const

const TABLE_COLUMNS = [
  { key: 'id', label: '$id', type: 'system-id', cellAlign: 'left' as const, width: '16%' },
  { key: 'driver', label: 'driver', type: 'string', cellAlign: 'left' as const, width: '28%' },
  { key: 'code', label: 'code', type: 'string', cellAlign: 'left' as const, width: '9%' },
  { key: 'team', label: 'team', type: 'string', cellAlign: 'left' as const, width: '16%' },
  { key: 'position', label: 'pos', type: 'integer', cellAlign: 'right' as const, width: '11%' },
  { key: 'lap', label: 'lap_ms', type: 'integer', cellAlign: 'right' as const, width: '20%' },
] as const

const TABLE_CHECKBOX_COL_WIDTH = 28
const TABLE_CELL_X = 'px-2'
const TABLE_HEADER_Y = 'py-1.5'
const TABLE_BODY_Y = 'py-1'
const TABLE_TEXT = 'text-[11px]'
const TABLE_HEADER_TEXT = 'text-[11px]'

function TablesDbColumnIcon({ type }: { type: string }) {
  const Icon = type === 'system-id' ? getColumnIcon('system-id') : getColumnIcon(type)

  return <Icon className="size-3 shrink-0 text-muted-foreground" aria-hidden />
}

const spreadsheetHeaderCellClass =
  'border-e border-border shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'
const spreadsheetLastHeaderCellClass =
  'shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'
const spreadsheetBodyCellClass = 'border-b border-e border-border'
const spreadsheetLastBodyCellClass = 'border-b border-border'

const EMPTY_TABLE_ROW_COUNT = 4

function SpreadsheetCheckboxPlaceholder({ checked = false }: { checked?: boolean }) {
  return (
    <div
      className={cn(
        'mx-auto flex size-3 items-center justify-center rounded-[3px] border',
        checked
          ? 'border-primary bg-primary text-primary-foreground'
          : 'border-border bg-background',
      )}
      aria-hidden
    >
      {checked ? <Check className="size-2.5 stroke-[3]" /> : null}
    </div>
  )
}

function SpreadsheetCheckboxCell({ checked = false }: { checked?: boolean }) {
  return (
    <td
      className={cn(
        'border-b border-border px-1.5 py-1 text-center',
        'shadow-[inset_-1px_0_0_0_var(--border)]',
      )}
    >
      <SpreadsheetCheckboxPlaceholder checked={checked} />
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
            className={cn(TABLE_CELL_X, TABLE_BODY_Y, isLast ? spreadsheetLastBodyCellClass : spreadsheetBodyCellClass)}
          >
            <span className="block min-h-[14px]" />
          </td>
        )
      })}
    </tr>
  )
}

function TablesDbPanel({ playKey }: { playKey: number }) {
  const t = useT()
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
    <div className={cn('flex min-h-0 flex-1 flex-col overflow-hidden', productBentoContainer.panel)}>
      <div className="border-b border-border bg-muted/20 px-3 py-2">
        <div className="flex items-start justify-between gap-2">
          <p className="min-w-0 font-mono text-[11px] text-muted-foreground">
            monaco_gp / <span className={cn('font-medium', productBentoIdle.text)}>lap_times</span>
          </p>
          <p
            className={cn(
              'shrink-0 text-[10px] tabular-nums text-muted-foreground transition-[color,transform] duration-300',
              rowCount === 847 && 'group-hover:scale-105 group-hover:text-foreground',
            )}
          >
            {rowCount} {t('rows')}
          </p>
        </div>
      </div>

      <div className="flex items-center gap-2 border-b border-border bg-background px-3 py-1.5">
        <p className="min-w-0 flex-1 truncate font-mono text-[10px]">
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

      <div className="min-h-0 flex-1 overflow-hidden bg-background">
        <table className="w-full table-fixed border-collapse bg-background">
          <colgroup>
            <col style={{ width: TABLE_CHECKBOX_COL_WIDTH }} />
            {TABLE_COLUMNS.map((column) => (
              <col key={column.key} style={{ width: column.width }} />
            ))}
          </colgroup>
          <thead className="sticky top-0 z-20 bg-background">
            <tr>
              <th
                className={cn(
                  'w-7 px-1.5 text-center',
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
                      TABLE_CELL_X,
                      TABLE_HEADER_Y,
                      column.cellAlign === 'right' ? 'text-end' : 'text-start',
                      isLast ? spreadsheetLastHeaderCellClass : spreadsheetHeaderCellClass,
                    )}
                  >
                    <div
                      className={cn(
                        'flex min-w-0 items-center gap-1',
                        column.cellAlign === 'right' && 'justify-end',
                      )}
                    >
                      <TablesDbColumnIcon type={column.type} />
                      <span
                        className={cn(
                          'min-w-0 truncate font-medium',
                          TABLE_HEADER_TEXT,
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
            {TABLE_ROWS.map((row) => {
              const isSelectedRow =
                Boolean(row.highlight) && shouldAnimate && queryState === 'done'

              return (
              <tr
                key={row.id}
                className={cn(
                  shouldAnimate &&
                    row.highlight &&
                    'product-bento-db-row-highlight motion-reduce:animate-none',
                  isSelectedRow && 'bg-muted',
                  'transition-[background-color] duration-300 hover:bg-muted/50 motion-reduce:hover:bg-transparent',
                )}
                style={
                  shouldAnimate && row.highlight
                    ? { animationDelay: `${row.revealDelayMs}ms` }
                    : undefined
                }
              >
                <SpreadsheetCheckboxCell checked={isSelectedRow} />
                {TABLE_COLUMNS.map((column, columnIndex) => {
                  const isLast = columnIndex === TABLE_COLUMNS.length - 1
                  const value = row[column.key]
                  const isMutedValue =
                    column.key === 'team' ||
                    column.key === 'code' ||
                    column.key === 'position' ||
                    column.type === 'system-id'
                  const isAccentValue = column.key === 'driver' || column.key === 'lap'

                  return (
                    <td
                      key={column.key}
                      className={cn(
                        TABLE_CELL_X,
                        TABLE_BODY_Y,
                        isLast ? spreadsheetLastBodyCellClass : spreadsheetBodyCellClass,
                        column.cellAlign === 'right' && 'text-end',
                        column.type === 'system-id' && 'font-mono',
                      )}
                    >
                      <span
                        className={cn(
                          'block truncate',
                          TABLE_TEXT,
                          isMutedValue && 'text-muted-foreground',
                          isAccentValue && productBentoIdle.text,
                          column.type === 'integer' && 'tabular-nums',
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
              )
            })}
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
  const t = useT()
  const shouldAnimate = playKey > 0
  const revealClass = shouldAnimate ? 'product-bento-db-reveal motion-reduce:opacity-100' : undefined

  return (
    <div
      key={playKey}
      className={cn('flex min-h-0 flex-1 flex-col overflow-hidden', productBentoContainer.panel)}
    >
      <div className="flex items-center justify-between gap-2 border-b border-border bg-muted/5 px-3.5 py-2.5">
        <div className={cn('min-w-0', revealClass)} style={shouldAnimate ? { animationDelay: '0ms' } : undefined}>
          <p className={cn('text-[12px] font-medium', productBentoIdle.text)}>race_briefings</p>
          <p className="truncate text-[10px] text-muted-foreground">Monaco GP strategy</p>
        </div>
        <Badge
          variant="inactive"
          className={cn(
            'h-5 shrink-0 px-1.5 text-[10px] transition-[color,background-color] duration-300 group-hover:bg-green-500/10 group-hover:text-green-700 dark:group-hover:text-green-400',
            revealClass,
          )}
          style={shouldAnimate ? { animationDelay: '420ms' } : undefined}
        >
          {t('Live')}
        </Badge>
      </div>
      <div className="min-h-0 flex-1 overflow-hidden p-3 font-mono text-[11px] leading-relaxed sm:text-[12px]">
        <div className={revealClass} style={shouldAnimate ? { animationDelay: '60ms' } : undefined}>
          <Syn tone="punctuation">{'{'}</Syn>
        </div>
        <div className={cn(revealClass, 'ps-2')} style={shouldAnimate ? { animationDelay: '120ms' } : undefined}>
          <Syn tone="property">&quot;event&quot;</Syn>
          <Syn tone="punctuation">: </Syn>
          <Syn tone="string">&quot;Monaco GP&quot;</Syn>
          <Syn tone="punctuation">,</Syn>
        </div>
        <div className={cn(revealClass, 'ps-2')} style={shouldAnimate ? { animationDelay: '180ms' } : undefined}>
          <Syn tone="property">&quot;session&quot;</Syn>
          <Syn tone="punctuation">: </Syn>
          <Syn tone="string">&quot;Race&quot;</Syn>
          <Syn tone="punctuation">,</Syn>
        </div>
        <div className={cn(revealClass, 'ps-2')} style={shouldAnimate ? { animationDelay: '260ms' } : undefined}>
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
        <div className={cn(revealClass, 'ps-2')} style={shouldAnimate ? { animationDelay: '340ms' } : undefined}>
          <Syn tone="property">&quot;strategy&quot;</Syn>
          <Syn tone="punctuation">: </Syn>
          <Syn tone="string">&quot;Medium stint, pit 14-17&quot;</Syn>
        </div>
        <div className={revealClass} style={shouldAnimate ? { animationDelay: '400ms' } : undefined}>
          <Syn tone="punctuation">{'}'}</Syn>
        </div>
      </div>
    </div>
  )
}

function VectorScoreBar({
  score,
  delayMs,
  shouldAnimate,
}: {
  score: number
  delayMs: number
  shouldAnimate: boolean
}) {
  const width = `${Math.round(score * 100)}%`

  return (
    <div className="flex min-w-[3.25rem] flex-col items-end gap-1">
      <span className="font-mono text-[10px] tabular-nums text-muted-foreground transition-colors duration-300 group-hover:text-foreground sm:text-[11px]">
        {Math.round(score * 100)}%
      </span>
      <div className="h-1 w-full overflow-hidden rounded-full bg-muted/40">
        <div
          className={cn(
            'h-full rounded-full bg-muted-foreground/25 transition-[width,background-color] duration-500 group-hover:bg-[var(--brand-cta)]/75 motion-reduce:group-hover:bg-muted-foreground/25',
            shouldAnimate && 'product-bento-db-reveal motion-reduce:opacity-100',
          )}
          style={{
            width,
            ...(shouldAnimate ? { animationDelay: `${delayMs}ms` } : {}),
            transitionDelay: `${delayMs}ms`,
          }}
        />
      </div>
    </div>
  )
}

function VectorsDbPanel({ playKey }: { playKey: number }) {
  const t = useT()
  const shouldAnimate = playKey > 0
  const revealClass = shouldAnimate ? 'product-bento-db-reveal motion-reduce:opacity-100' : undefined
  const resultRevealClass = shouldAnimate
    ? 'product-bento-db-result-reveal motion-reduce:opacity-100'
    : undefined

  return (
    <div
      key={playKey}
      className={cn('flex min-h-0 flex-1 flex-col overflow-hidden', productBentoContainer.panel)}
    >
      <div
        className={cn('border-b border-border bg-muted/10 px-3 py-2', revealClass)}
        style={shouldAnimate ? { animationDelay: '0ms' } : undefined}
      >
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <p className="font-mono text-[10px] text-muted-foreground sm:text-[11px]">
              race_notes /{' '}
              <span className={cn('font-medium', productBentoIdle.text)}>strategy_embeddings</span>
            </p>
            <p className="mt-0.5 truncate text-[10px] text-muted-foreground">
              {t('Semantic search over race briefings and telemetry notes')}
            </p>
          </div>
          <div className="flex shrink-0 flex-wrap justify-end gap-1">
            <Badge variant="inactive" className="h-5 px-1.5 text-[9px] sm:text-[10px]">
              1536d
            </Badge>
            <Badge
              variant="inactive"
              className="h-5 px-1.5 text-[9px] transition-[color,background-color,border-color] duration-300 group-hover:border-[var(--brand-cta)]/25 group-hover:bg-[var(--brand-cta)]/10 group-hover:text-[var(--brand-cta)] sm:text-[10px]"
            >
              cosine
            </Badge>
          </div>
        </div>
      </div>

      <div className="space-y-2 border-b border-border px-3 py-2.5">
        <div
          className={cn(
            'rounded-md border border-border/80 bg-muted/8 px-2.5 py-2',
            revealClass,
          )}
          style={shouldAnimate ? { animationDelay: '60ms' } : undefined}
        >
          <p className="text-[9px] uppercase tracking-wider text-muted-foreground sm:text-[10px]">
            {t('Query')}
          </p>
          <p className="mt-1 truncate font-mono text-[11px] sm:text-[12px]">
            <Syn tone="string">&quot;Monaco undercut on Medium&quot;</Syn>
          </p>
        </div>
        <div
          className={cn('flex flex-wrap gap-1', revealClass)}
          style={shouldAnimate ? { animationDelay: '120ms' } : undefined}
        >
          {VECTOR_FILTERS.map((filter, index) => (
            <span
              key={filter}
              className={cn(
                'rounded-md border border-border bg-background px-1.5 py-0.5 font-mono text-[9px] text-muted-foreground transition-[border-color,background-color,color] duration-300 sm:text-[10px]',
                'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_22%,var(--border))] group-hover:bg-[color-mix(in_srgb,var(--brand-cta)_8%,var(--background))] group-hover:text-foreground motion-reduce:group-hover:border-border motion-reduce:group-hover:bg-background motion-reduce:group-hover:text-muted-foreground',
              )}
              style={{ transitionDelay: `${index * 40}ms` }}
            >
              {filter}
            </span>
          ))}
        </div>
      </div>

      <div className="min-h-0 flex-1 space-y-1.5 overflow-hidden px-3 py-2.5">
        {VECTOR_RESULTS.map((row, index) => (
          <div
            key={`${row.title}-${playKey}`}
            className={cn(
              'rounded-md border border-border/70 bg-muted/5 px-2.5 py-2',
              resultRevealClass,
            )}
            style={shouldAnimate ? { animationDelay: `${120 + index * 160}ms` } : undefined}
          >
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0 flex-1">
                <p
                  className={cn(
                    'truncate text-[11px] font-medium sm:text-[12px]',
                    productBentoIdle.text,
                  )}
                >
                  {row.title}
                </p>
                <p className="mt-0.5 line-clamp-2 text-[10px] leading-snug text-muted-foreground sm:text-[11px]">
                  {row.snippet}
                </p>
              </div>
              <VectorScoreBar score={row.score} delayMs={row.delayMs} shouldAnimate={shouldAnimate} />
            </div>
            <p className="mt-1.5 truncate font-mono text-[9px] text-muted-foreground sm:text-[10px]">
              <span className={cn('transition-colors duration-300', productBentoIdle.text)}>
                {row.source}
              </span>
            </p>
          </div>
        ))}
      </div>

      <div
        className={cn(
          'flex items-end justify-between gap-2 border-t border-border bg-muted/5 px-3 py-1.5',
          revealClass,
        )}
        style={shouldAnimate ? { animationDelay: '560ms' } : undefined}
      >
        <div className="min-w-0">
          <VectorsDbSearchSnippet />
        </div>
        <p className="shrink-0 text-[9px] tabular-nums text-muted-foreground transition-colors duration-300 group-hover:text-foreground sm:text-[10px]">
          {VECTOR_RESULTS.length} {t('matches')} · 14ms
        </p>
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
        'flex min-w-0 items-center gap-2.5 px-2.5 py-2 transition-colors duration-300 sm:gap-3 sm:px-3 sm:py-2.5',
        productBentoContainer.panel,
        'group-hover:bg-accent/15 motion-reduce:group-hover:bg-card/70',
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
  const t = useT()
  return (
    <div className="flex items-center gap-2 px-1">
      <div className="h-px flex-1 bg-border" />
      <span className="text-[9px] font-medium uppercase tracking-wider text-muted-foreground">
        {t('or')}
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
    'absolute inset-x-0 top-0 bottom-0 mt-0 flex min-h-0 w-full flex-col overflow-hidden px-3.5 focus-visible:outline-none'

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

        <div className="pointer-events-none absolute inset-x-0 bottom-0 z-50 flex justify-center px-3 pb-0.5">
          <TabsList className="pointer-events-auto inline-flex h-auto w-auto gap-1 rounded-lg border border-border/45 bg-background/80 p-1 shadow-sm">
            {APPWRITE_TABS.map((tab) => {
              const Icon = tab.Icon

              return (
                <TabsTrigger
                  key={tab.id}
                  value={tab.id}
                  className="h-auto gap-1.5 rounded-md border border-transparent px-2.5 py-1.5 text-[10px] text-muted-foreground transition-[color,background-color,border-color,box-shadow] duration-300 data-[state=active]:border-border/40 data-[state=active]:bg-background/70 data-[state=active]:text-muted-foreground data-[state=active]:shadow-sm sm:text-[11px] group-hover:data-[state=active]:border-border/55 group-hover:data-[state=active]:bg-background/90 group-hover:data-[state=active]:text-foreground [&_svg:not([class*='size-'])]:size-3"
                >
                  <Icon aria-hidden />
                  <span className="truncate">{tab.label}</span>
                </TabsTrigger>
              )
            })}
          </TabsList>
        </div>
      </div>

      <div className="relative z-10 shrink-0 bg-card/10 px-3.5 pb-3 pt-4 sm:pb-3.5">
        <NativeDbOrSeparator />
        <div className="mt-2.5">
          <NativeDbSelectionStrip />
        </div>
      </div>
    </Tabs>
  )
}
