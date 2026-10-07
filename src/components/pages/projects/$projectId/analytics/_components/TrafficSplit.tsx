import { useMemo, type ReactNode } from 'react'
import { BreakdownRow } from './BreakdownRow'
import { useAnalyticsCardTab } from '@/hooks/use-analytics-card-tab'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { AnalyticsDimension, type Models } from '@appwrite.io/console'
import { Bot, User } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  useAnalyticsBreakdown,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import { USAGE_CHART_FADE_IN_CLASS_NAME } from '@/lib/usage/usage-chart-loading'
import { MetricInfo } from './MetricInfo'
import { useAnalyticsFilters } from './analytics-filters-context'
import { isKnownBreakdownValue } from '@/lib/analytics/breakdown-values'
import { formatNumber } from './format'
import { BreakdownSkeleton } from './BreakdownPanel'

type SplitMeasure = 'visitors' | 'events'
const SPLIT_MEASURES: readonly SplitMeasure[] = ['visitors', 'events']

/** Rows per list under the bar (types, agents), sized to the card height. */
const BOT_LIST_ROWS = 5

/** The Humans / Bots figures double as "only humans" / "only bots" filters. */
const KIND_BUTTON_CLASS =
  '-m-1.5 flex min-w-0 cursor-pointer items-center gap-3 rounded-lg p-1.5 text-start transition-colors hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
const KIND_ACTIVE_CLASS = 'bg-muted/60 ring-1 ring-border'
/** Same layout with the filter flag off: not interactive. */
const KIND_STATIC_CLASS =
  '-m-1.5 flex min-w-0 cursor-default items-center gap-3 rounded-lg p-1.5 text-start'

/**
 * Humans use the Appwrite brand purple (same value as `--network-globe-edge`),
 * solid. The hatched bot segments use the chart palette so the two sides read
 * as different kinds of traffic at a glance.
 */
const HUMAN_COLOR = '#7c67fe'
const HUMAN_ICON_COLOR = HUMAN_COLOR
/**
 * Fixed bot palette, deliberately without purple or blue: the theme chart
 * palette has purple/blue hues in dark mode that would blur into the humans
 * segment.
 */
const BOT_COLORS = ['#f59e0b', '#14b8a6', '#f97316', '#ec4899', '#64748b']

/** Diagonal hatching marks machine traffic; people stay solid. */
const BOT_HATCH =
  'repeating-linear-gradient(135deg, rgba(255,255,255,0.22) 0 4px, transparent 4px 8px)'

type Segment = {
  key: string
  label: string
  value: number
  color: string
  kind: 'human' | 'bot' | 'unclassified'
  /** Raw `botCategory` value, when this segment can be filtered on. */
  category?: string
}

/**
 * `trafficType` values aren't enumerated in the SDK, so classify loosely:
 * anything that says "human" is people, empty is unclassified (the API
 * couldn't tell), everything else is automated.
 */
function classifyTrafficType(
  value: string | undefined,
): 'human' | 'bot' | 'unclassified' {
  const normalized = value?.trim().toLowerCase() ?? ''
  if (!normalized) return 'unclassified'
  if (normalized.includes('human')) return 'human'
  return 'bot'
}

const ACRONYMS = new Set(['ai', 'seo', 'api', 'rss', 'llm'])

/** `ai_crawler` → "AI crawler". */
function humanizeCategory(value: string): string {
  const words = value.trim().split(/[_\-\s]+/).filter(Boolean)
  return words
    .map((word, index) => {
      const lower = word.toLowerCase()
      if (ACRONYMS.has(lower)) return lower.toUpperCase()
      return index === 0 ? lower.charAt(0).toUpperCase() + lower.slice(1) : lower
    })
    .join(' ')
}

function splitTotals(rows: Models.AnalyticsMetric[], measure: SplitMeasure) {
  return rows.reduce(
    (acc, row) => {
      acc[classifyTrafficType(row.value)] += row[measure] ?? 0
      return acc
    },
    { human: 0, bot: 0, unclassified: 0 },
  )
}

function share(value: number, total: number): number {
  return total > 0 ? (value / total) * 100 : 0
}

function formatShare(value: number): string {
  if (value > 0 && value < 0.1) return '<0.1%'
  return `${value.toFixed(value >= 10 || value === 0 ? 0 : 1)}%`
}

type TrafficSplitProps = {
  projectId: string
  propertyId: string
  range: AnalyticsRange
  /** Comparison window for the "pts vs …" delta; `null` when off. */
  comparisonRange: AnalyticsRange | null
  compareLabel: string
}

/**
 * "Power bar" card: people on one end, automated traffic on the other, the
 * bot side split by category (listed below the bar) and the top agents named
 * along the bottom.
 */
export function TrafficSplit({
  projectId,
  propertyId,
  range,
  comparisonRange,
  compareLabel,
}: TrafficSplitProps) {
  const t = useT()
  // Remembered like the breakdown cards' tabs.
  const [storedMeasure, setMeasure] = useAnalyticsCardTab(
    'humans-vs-bots',
    SPLIT_MEASURES,
    'visitors',
  )
  const measure: SplitMeasure =
    storedMeasure === 'events' ? 'events' : 'visitors'

  const {
    filters,
    addEqualFilter,
    isFilterActive,
    trafficKind,
    toggleTrafficKind,
  } = useAnalyticsFilters()
  // Humans/bots filtering is unverified against the backend (see the flag).
  const { features } = useConsoleProfile()
  const trafficFilterEnabled = features.analyticsTrafficFilter

  const { breakdown: trafficTypes, isLoading, error } = useAnalyticsBreakdown(
    projectId,
    propertyId,
    AnalyticsDimension.TrafficType,
    range,
    undefined,
    true,
    filters,
  )
  const { breakdown: categories } = useAnalyticsBreakdown(
    projectId,
    propertyId,
    AnalyticsDimension.BotCategory,
    range,
    undefined,
    true,
    filters,
  )
  const { breakdown: botNames } = useAnalyticsBreakdown(
    projectId,
    propertyId,
    AnalyticsDimension.BotName,
    range,
    undefined,
    true,
    filters,
  )
  const { breakdown: comparisonTrafficTypes } = useAnalyticsBreakdown(
    projectId,
    comparisonRange ? propertyId : null,
    AnalyticsDimension.TrafficType,
    comparisonRange ?? range,
    undefined,
    true,
    filters,
  )

  const totals = useMemo(
    () => splitTotals(trafficTypes, measure),
    [trafficTypes, measure],
  )
  // Unclassified traffic (no traffic type) is left out, like unknown rows in
  // the cards: shares are among humans and bots only.
  const total = totals.human + totals.bot

  const segments = useMemo((): Segment[] => {
    const result: Segment[] = [
      {
        key: 'human',
        label: t('Humans'),
        value: totals.human,
        color: HUMAN_COLOR,
        kind: 'human',
      },
    ]

    // Split the bot side by category. Categories may not cover every bot
    // event (unrecognised agents), so the remainder becomes "Other bots".
    const named = categories
      .filter((row) => isKnownBreakdownValue(row.value))
      .map((row) => ({ value: row.value!, amount: row[measure] ?? 0 }))
      .filter((row) => row.amount > 0)
      .sort((a, b) => b.amount - a.amount)

    let assigned = 0
    named.forEach((row, index) => {
      // Never let categories exceed the bot total the traffic split reports.
      const amount = Math.min(row.amount, Math.max(0, totals.bot - assigned))
      if (amount <= 0) return
      assigned += amount
      result.push({
        key: `bot-${row.value}`,
        label: humanizeCategory(row.value),
        value: amount,
        color: BOT_COLORS[index % BOT_COLORS.length],
        kind: 'bot',
        category: row.value,
      })
    })
    const remainder = totals.bot - assigned
    if (remainder > 0) {
      result.push({
        key: 'bot-other',
        label: named.length > 0 ? t('Other bots') : t('Bots'),
        value: remainder,
        color: BOT_COLORS[named.length % BOT_COLORS.length],
        kind: 'bot',
      })
    }
    return result
  }, [categories, measure, totals, t])

  const humanShare = share(totals.human, total)
  const botShare = share(totals.bot, total)

  const comparisonDelta = useMemo(() => {
    if (!comparisonRange || comparisonTrafficTypes.length === 0) return undefined
    const previous = splitTotals(comparisonTrafficTypes, measure)
    const previousTotal = previous.human + previous.bot
    if (previousTotal <= 0 || total <= 0) return undefined
    return Number(
      (humanShare - share(previous.human, previousTotal)).toFixed(1),
    )
  }, [comparisonRange, comparisonTrafficTypes, measure, humanShare, total])

  const botSegments = segments.filter(
    (segment) => segment.kind === 'bot' && segment.value > 0,
  )

  // Ranked by the selected measure, like the bar.
  const topAgents = botNames
    .filter((row) => isKnownBreakdownValue(row.value) && (row[measure] ?? 0) > 0)
    .sort((a, b) => (b[measure] ?? 0) - (a[measure] ?? 0))
    .slice(0, BOT_LIST_ROWS)

  const maxSegment = botSegments.reduce((m, s) => Math.max(m, s.value), 0)
  const maxAgent = topAgents.reduce((m, a) => Math.max(m, a[measure] ?? 0), 0)

  const hasData = total > 0
  const isPending = isLoading && trafficTypes.length === 0

  return (
    // Stretches to its grid row, so it matches the breakdown card beside it.
    <section className="flex h-full flex-col rounded-lg border border-border bg-card">
      {/* Header: same shape as the breakdown cards. */}
      <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-2.5">
        <div className="min-w-0">
          <h3 className="flex items-center gap-1.5 text-[13px] font-semibold text-foreground">
            {t('Humans vs bots')}
            <MetricInfo info="humanShare" />
          </h3>
          <p className="mt-0.5 truncate text-[11px] text-muted-foreground">
            {t('People versus automated traffic, split by bot type')}
          </p>
        </div>
        <ToggleGroup
          type="single"
          variant="outline"
          value={measure}
          onValueChange={(next) => {
            if (next === 'visitors' || next === 'events') setMeasure(next)
          }}
          className="h-7 gap-0"
          aria-label={t('Measure')}
        >
          {(['visitors', 'events'] as const).map((value, index) => (
            <ToggleGroupItem
              key={value}
              value={value}
              className={cn(
                'h-7 min-w-[4.5rem] px-2 text-[11px] font-medium text-muted-foreground shadow-none data-[state=on]:bg-secondary data-[state=on]:text-secondary-foreground',
                '!rounded-none',
                index === 0 ? '!rounded-s-md' : '!rounded-e-md !border-s-0',
              )}
            >
              {value === 'visitors' ? t('Visitors') : t('Events')}
            </ToggleGroupItem>
          ))}
        </ToggleGroup>
      </div>

      <div className="flex flex-1 flex-col px-4 pb-4 pt-6">
      {/* End labels */}
      <div className="mb-4 flex items-end justify-between gap-4">
        <button
          type="button"
          disabled={!trafficFilterEnabled}
          onClick={() => toggleTrafficKind('human')}
          aria-pressed={trafficFilterEnabled ? trafficKind === 'human' : undefined}
          title={
            !trafficFilterEnabled
              ? undefined
              : trafficKind === 'human'
                ? t('Remove filter')
                : t('Show humans only')
          }
          className={cn(
            trafficFilterEnabled ? KIND_BUTTON_CLASS : KIND_STATIC_CLASS,
            trafficKind === 'human' && KIND_ACTIVE_CLASS,
          )}
        >
          <span
            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg"
            style={{
              backgroundColor: `color-mix(in oklch, ${HUMAN_COLOR} 14%, transparent)`,
            }}
          >
            <User className="h-5 w-5" style={{ color: HUMAN_ICON_COLOR }} />
          </span>
          <div className="min-w-0">
            <p className="mb-1 text-[12px] text-muted-foreground">{t('Humans')}</p>
            <p className="flex items-baseline gap-2">
              <span
                className={cn(
                  'text-[30px] font-semibold leading-none tabular-nums text-foreground',
                  hasData && USAGE_CHART_FADE_IN_CLASS_NAME,
                )}
              >
                {isPending || error ? '-' : formatShare(humanShare)}
              </span>
              {hasData ? (
                <span className="text-[12px] tabular-nums text-muted-foreground">
                  {formatNumber(totals.human)}
                </span>
              ) : null}
              {comparisonDelta !== undefined ? (
                <span
                  className={cn(
                    'text-[12px] font-medium tabular-nums',
                    comparisonDelta > 0 &&
                      'text-emerald-600 dark:text-emerald-400',
                    comparisonDelta < 0 && 'text-amber-600 dark:text-amber-400',
                    comparisonDelta === 0 && 'text-muted-foreground',
                  )}
                  title={`${t('vs')} ${compareLabel}`}
                >
                  {comparisonDelta > 0 ? '+' : ''}
                  {comparisonDelta} {t('pts')}
                </span>
              ) : null}
            </p>
          </div>
        </button>
        <button
          type="button"
          disabled={!trafficFilterEnabled}
          onClick={() => toggleTrafficKind('bot')}
          aria-pressed={trafficFilterEnabled ? trafficKind === 'bot' : undefined}
          title={
            !trafficFilterEnabled
              ? undefined
              : trafficKind === 'bot'
                ? t('Remove filter')
                : t('Show bots only')
          }
          className={cn(
            trafficFilterEnabled ? KIND_BUTTON_CLASS : KIND_STATIC_CLASS,
            'text-end',
            trafficKind === 'bot' && KIND_ACTIVE_CLASS,
          )}
        >
          <div className="min-w-0">
            <p className="mb-1 text-[12px] text-muted-foreground">{t('Bots')}</p>
            <p className="flex items-baseline justify-end gap-2">
              {hasData ? (
                <span className="text-[12px] tabular-nums text-muted-foreground">
                  {formatNumber(totals.bot)}
                </span>
              ) : null}
              <span
                className={cn(
                  'text-[30px] font-semibold leading-none tabular-nums text-foreground',
                  hasData && USAGE_CHART_FADE_IN_CLASS_NAME,
                )}
              >
                {isPending || error ? '-' : formatShare(botShare)}
              </span>
            </p>
          </div>
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-muted">
            <Bot className="h-5 w-5 text-muted-foreground" />
          </span>
        </button>
      </div>

      {/* The bar */}
      <TooltipProvider delayDuration={0}>
        <div
          className="relative flex h-5 w-full gap-[3px] overflow-hidden rounded-full bg-muted"
          role="img"
          aria-label={
            hasData
              ? `${t('Humans')} ${formatShare(humanShare)}, ${t('Bots')} ${formatShare(botShare)}`
              : t('No traffic in this range')
          }
        >
          {isPending ? (
            <div className="h-full w-full animate-pulse bg-muted" />
          ) : hasData ? (
            segments
              .filter((segment) => segment.value > 0)
              .map((segment) => {
                const width = share(segment.value, total)
                return (
                  <Tooltip key={segment.key}>
                    <TooltipTrigger asChild>
                      <div
                        // A bot type → that category. Behind the flag:
                        // humans → humans only, "Other bots" → bots only.
                        onClick={
                          segment.category
                            ? () => addEqualFilter('botCategory', segment.category!)
                            : trafficFilterEnabled
                              ? () =>
                                  toggleTrafficKind(
                                    segment.kind === 'human' ? 'human' : 'bot',
                                  )
                              : undefined
                        }
                        className={cn(
                          'h-full min-w-[3px] transition-[width] duration-700 ease-out first:rounded-s-full last:rounded-e-full hover:brightness-110 motion-reduce:transition-none',
                          (segment.category || trafficFilterEnabled) &&
                            'cursor-pointer',
                        )}
                        style={{
                          width: `${width}%`,
                          backgroundColor: segment.color,
                          backgroundImage:
                            segment.kind === 'bot' ? BOT_HATCH : undefined,
                          opacity: segment.kind === 'unclassified' ? 0.4 : 1,
                        }}
                      />
                    </TooltipTrigger>
                    <TooltipContent side="top" className="text-[12px]">
                      <span className="font-medium">{segment.label}</span>{' '}
                      · {formatShare(width)} · {formatNumber(segment.value)}{' '}
                      {measure === 'visitors' ? t('visitors') : t('events')}
                    </TooltipContent>
                  </Tooltip>
                )
              })
          ) : null}
        </div>
      </TooltipProvider>

      {/* Bot types and named agents side by side (stacked when narrow),
          ranked rows with bars like the other cards. Shares are of all
          traffic, so they add up with the humans figure above. */}
      <div className="@container mt-7 min-h-0 flex-1">
        <div className="grid h-full gap-x-6 gap-y-4 @md:grid-cols-2">
          <BotColumn
            title={t('By type')}
            error={error}
            isPending={isPending}
            emptyLabel={
              hasData ? t('No bot traffic in this range') : t('No traffic in this range')
            }
          >
            {botSegments.slice(0, BOT_LIST_ROWS).map((segment) => {
              const category = segment.category
              const active = category ? isFilterActive('botCategory', category) : false
              return (
                <BreakdownRow
                  key={segment.key}
                  label={segment.label}
                  value={segment.value}
                  share={share(segment.value, total)}
                  barPercent={share(segment.value, maxSegment)}
                  color={segment.color}
                  leading={
                    <span
                      className="h-2.5 w-2.5 shrink-0 rounded-sm"
                      style={{ backgroundColor: segment.color, backgroundImage: BOT_HATCH }}
                    />
                  }
                  // "Other bots" has no single category value to filter on.
                  {...(category
                    ? {
                        onClick: () => addEqualFilter('botCategory', category),
                        active,
                        actionTitle: active
                          ? t('Remove filter')
                          : t('Filter by this category'),
                      }
                    : {})}
                />
              )
            })}
          </BotColumn>

          <BotColumn
            title={t('Top agents')}
            error={error}
            isPending={isPending}
            emptyLabel={t('No named agents in this range')}
          >
            {topAgents.map((agent) => {
              const name = agent.value!
              const value = agent[measure] ?? 0
              const active = isFilterActive('botName', name)
              return (
                <BreakdownRow
                  key={name}
                  label={name}
                  value={value}
                  share={share(value, total)}
                  barPercent={share(value, maxAgent)}
                  mono
                  onClick={() => addEqualFilter('botName', name)}
                  active={active}
                  actionTitle={active ? t('Remove filter') : t('Filter by this bot')}
                />
              )
            })}
          </BotColumn>
        </div>
      </div>

      </div>
    </section>
  )
}

/** One titled list under the bar: skeleton, error, empty or rows. */
function BotColumn({
  title,
  error,
  isPending,
  emptyLabel,
  children,
}: {
  title: string
  error: unknown
  isPending: boolean
  emptyLabel: string
  children: ReactNode[]
}) {
  const t = useT()
  return (
    <div className="min-w-0">
      <p className="mb-1.5 px-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
        {title}
      </p>
      {error ? (
        <p className="px-2 py-1 text-[12px] text-muted-foreground">
          {error instanceof Error ? error.message : t('Could not load analytics data')}
        </p>
      ) : isPending ? (
        <BreakdownSkeleton rows={BOT_LIST_ROWS} />
      ) : children.length === 0 ? (
        <p className="px-2 py-1 text-[12px] text-muted-foreground">{emptyLabel}</p>
      ) : (
        <div className="space-y-0.5">{children}</div>
      )}
    </div>
  )
}
