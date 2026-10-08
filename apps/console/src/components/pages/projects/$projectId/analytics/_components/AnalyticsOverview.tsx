import { useMemo, useState, type KeyboardEvent, type ReactNode } from 'react'
import { useQueries } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import {
  Area,
  CartesianGrid,
  ComposedChart,
  Line,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import type { Models } from '@appwrite.io/console'
import { cn } from '@/lib/utils'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { createCompactCountAxisTickFormatter } from '@/lib/usage/format-metric'
import { USAGE_CHART_RESPONSIVE_CONTAINER_PROPS } from '@/lib/usage/chart-layout'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import { USAGE_CHART_FADE_IN_CLASS_NAME } from '@/lib/usage/usage-chart-loading'
import { useUsageChartBrushSelect } from '@/hooks/use-usage-chart-brush'
import {
  UsageChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import { AnimatedCounter } from '@/components/global/shared/AnimatedCounter'
import { Button } from '@/components/ui/button'
import { UsageChartBrushReferenceArea } from '../../usage/_components/UsageChartBrushReferenceArea'
import {
  OVERVIEW_CHART_HEIGHT,
  overviewChartPanelBodyClass,
  overviewChartPanelChartAreaClass,
  overviewChartPanelChartFillClass,
  overviewChartPanelErrorClass,
} from '../../overview/chart-panel'
import {
  ANALYTICS_PAGEVIEW_EVENT,
  analyticsStatsQueryOptions,
  useAnalyticsEventMetrics,
  useAnalyticsStats,
  type AnalyticsChartInterval,
  type AnalyticsCompareMode,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import { compareModeLabel, formatAnalyticsRangeLabel } from './CompareControl'
import { MetricInfo, type AnalyticsMetricInfoKey } from './MetricInfo'
import { useAnalyticsFilters } from './analytics-filters-context'
import { ActiveFilterChips } from './ActiveFilterChips'
import { ChangeBadge } from './ChangeBadge'
import { ANALYTICS_FILTER_UNSUPPORTED_MESSAGE } from '@/lib/analytics/analytics-filters'
import { useT } from '@/lib/i18n/translate'
import {
  analyticsChangePercent,
  buildAnalyticsChartPoints,
  buildBucketWindows,
  type AnalyticsBucketWindow,
  type AnalyticsChartPoint,
} from './chart-series'
import {
  formatDuration,
  formatNumber,
  formatPercent,
  formatRatio,
} from './format'

const CURRENT_COLOR = 'var(--chart-brand)'
const PREVIOUS_COLOR = 'var(--muted-foreground)'
const GRADIENT_ID = 'analyticsOverviewGradient'
const SKELETON_GRADIENT_ID = 'analyticsOverviewSkeletonGradient'
const SKELETON_CHART_STROKE = 'hsl(var(--muted-foreground) / 0.4)'
const SKELETON_CHART_FILL = 'hsl(var(--muted-foreground))'
const SKELETON_WAVE = [
  0.42, 0.58, 0.51, 0.68, 0.59, 0.72, 0.64, 0.7, 0.55, 0.74, 0.62, 0.69,
] as const

/** Every overview metric; each one can drive the chart. */
export type AnalyticsChartMetric =
  | 'visitors'
  | 'visits'
  | 'pageviews'
  | 'viewsPerVisit'
  | 'bounceRate'
  | 'visitDuration'
  | 'engagementTime'

type SeriesMetric = 'visitors' | 'visits' | 'pageviews'
type AggregateMetric = Exclude<AnalyticsChartMetric, SeriesMetric>

/**
 * The tabs at the top of the overview: the seven metrics that matter most for
 * a website (scroll depth stays in the exports). Every tab drives the chart,
 * from one of two sources:
 * - `series`: the API's interval series (visitors, visits; pageviews via the
 *   pageview event), one request for the whole chart.
 * - `aggregate`: rates and durations only exist on the flat aggregate, so the
 *   chart reads it per bucket window (see `buildBucketWindows`).
 */
const OVERVIEW_METRICS: {
  key: AnalyticsChartMetric
  label: string
  info: AnalyticsMetricInfoKey
  format: (value: number) => string
  source: 'series' | 'aggregate'
  /** Y-axis formatting. */
  axis: 'count' | 'percent' | 'duration' | 'ratio'
  /** Lower is better: flip the change colouring. */
  invert?: boolean
}[] = [
  { key: 'visitors', label: 'Unique visitors', info: 'uniqueVisitors', format: formatNumber, source: 'series', axis: 'count' },
  { key: 'visits', label: 'Visits', info: 'visits', format: formatNumber, source: 'series', axis: 'count' },
  { key: 'pageviews', label: 'Pageviews', info: 'pageviews', format: formatNumber, source: 'series', axis: 'count' },
  { key: 'viewsPerVisit', label: 'Views per visit', info: 'viewsPerVisit', format: formatRatio, source: 'aggregate', axis: 'ratio' },
  { key: 'bounceRate', label: 'Bounce rate', info: 'bounceRate', format: formatPercent, source: 'aggregate', axis: 'percent', invert: true },
  { key: 'visitDuration', label: 'Visit duration', info: 'visitDuration', format: formatDuration, source: 'aggregate', axis: 'duration' },
  { key: 'engagementTime', label: 'Engagement time', info: 'engagementTime', format: formatDuration, source: 'aggregate', axis: 'duration' },
]

/** Value of a series-backed metric for one bucket. */
function bucketValue(
  metric: SeriesMetric,
  base: AnalyticsChartPoint | undefined,
  pageviews: AnalyticsChartPoint | undefined,
): number | undefined {
  if (metric === 'visitors') return base?.visitors
  if (metric === 'visits') return base?.sessions
  return pageviews?.events
}

function axisFormatter(
  axis: 'count' | 'percent' | 'duration' | 'ratio',
  max: number,
): (value: number) => string {
  if (axis === 'percent') return (value) => `${Math.round(value)}%`
  if (axis === 'duration') return (value) => formatDuration(value)
  if (axis === 'ratio') return (value) => (Math.round(value * 10) / 10).toString()
  return createCompactCountAxisTickFormatter(max)
}

/**
 * Spread per-window aggregate results over the bucket grid: the value sits on
 * each window's first bucket; grouped buckets after it stay null and the line
 * connects across them.
 */
function windowValues(
  bucketCount: number,
  windows: readonly AnalyticsBucketWindow[],
  results: readonly (Models.AnalyticsMetric | undefined)[],
  metric: AggregateMetric,
): (number | null)[] {
  const values: (number | null)[] = new Array(bucketCount).fill(null)
  windows.forEach((window, index) => {
    const aggregate = results[index]
    if (aggregate) values[window.index] = aggregate[metric] ?? 0
  })
  return values
}

// ─── Small building blocks ──────────────────────────────────────────────────

function MetricTab({
  label,
  info,
  value,
  format,
  change,
  invert,
  isActive,
  unavailable,
  hint,
  onClick,
}: {
  label: string
  info: AnalyticsMetricInfoKey
  value: number | undefined
  format: (value: number) => string
  change: number | undefined
  invert?: boolean
  isActive: boolean
  unavailable: boolean
  /** Shown on hover when the value can't be computed (e.g. filters). */
  hint?: string
  /** Omitted for aggregate-only metrics: the tab shows a value, no plot. */
  onClick?: () => void
}) {
  const interactive = !!onClick
  return (
    <div
      // A div with button semantics, because the info hint inside it is
      // itself a button and buttons cannot be nested.
      {...(interactive
        ? {
            role: 'button',
            tabIndex: 0,
            onClick,
            onKeyDown: (event: KeyboardEvent) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                onClick()
              }
            },
            'aria-pressed': isActive,
          }
        : {})}
      title={hint}
      className={cn(
        'flex min-w-0 select-none flex-col items-start gap-0.5 overflow-hidden rounded-md border px-3 py-1.5 text-start transition-colors',
        interactive &&
          'cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        isActive
          ? 'border-border bg-muted/50'
          : interactive
            ? 'border-transparent hover:bg-muted/30'
            : 'border-transparent',
      )}
    >
      <span className="flex min-w-0 max-w-full items-center gap-1.5 whitespace-nowrap text-[12px] text-muted-foreground">
        {isActive ? <ChartSeriesDot color={CURRENT_COLOR} /> : null}
        <span className="truncate" title={label}>
          {label}
        </span>
        <MetricInfo info={info} />
      </span>
      <span className="flex items-baseline gap-x-2 whitespace-nowrap">
        {unavailable || value === undefined ? (
          <span className="text-[20px] font-semibold text-muted-foreground">
            -
          </span>
        ) : (
          <>
            <AnimatedCounter
              value={value}
              formatDisplay={format}
              className={cn(
                'text-[20px] font-semibold',
                isActive || !interactive ? 'text-foreground' : 'text-foreground/80',
                USAGE_CHART_FADE_IN_CLASS_NAME,
              )}
            />
            <ChangeBadge change={change} invert={invert} />
          </>
        )}
      </span>
    </div>
  )
}

function ChartArea({ children }: { children: ReactNode }) {
  return (
    <div
      className={cn(overviewChartPanelBodyClass, FORCE_LTR_CLASS)}
      style={{ height: OVERVIEW_CHART_HEIGHT }}
    >
      <div className={overviewChartPanelChartAreaClass}>
        <div className={overviewChartPanelChartFillClass}>{children}</div>
      </div>
    </div>
  )
}

// ─── Overview ───────────────────────────────────────────────────────────────

type AnalyticsOverviewProps = {
  projectId: string
  propertyId: string
  /** Picker selection; drives the axis labels. */
  dateRange: DateRange
  /** Chart brush selection. */
  onDateRangeChange: (range: DateRange | undefined) => void
  range: AnalyticsRange
  interval: AnalyticsChartInterval
  activeSeries: AnalyticsChartMetric
  onActiveSeriesChange: (series: AnalyticsChartMetric) => void
  /** Loader-prefetched aggregate for the default window, if any. */
  fallbackStats?: Models.AnalyticsMetric
  /** Loader-prefetched series for the default window, if any. */
  fallbackSeries?: { points: Models.AnalyticsMetric[]; total: number }
  compareMode: AnalyticsCompareMode
  /** Resolved comparison window; `null` when comparison is off. */
  comparisonRange: AnalyticsRange | null
  /** "Try again" in the chart's error state. */
  onRefresh: () => void
}

/**
 * Full-width traffic overview: property-wide series totals (clicking one
 * switches the plotted series) with period-over-period change, the time
 * series against the comparison window.
 *
 * The controls (filters, interval, date range, comparison, refresh) live in
 * the page's ServiceHeader toolbar, like every other detail view.
 */
export function AnalyticsOverview({
  projectId,
  propertyId,
  dateRange,
  onDateRangeChange,
  range,
  interval,
  activeSeries,
  onActiveSeriesChange,
  fallbackStats,
  fallbackSeries,
  compareMode,
  comparisonRange,
  onRefresh,
}: AnalyticsOverviewProps) {
  const t = useT()
  const [hasRevealed, setHasRevealed] = useState(false)
  const isComparing = comparisonRange !== null
  // Hooks can't be conditional: with comparison off, pass a null property ID
  // so the comparison queries stay disabled, and any range as a placeholder.
  const comparePropertyId = isComparing ? propertyId : null
  const compareRange = comparisonRange ?? range
  const compareLabel = t(compareModeLabel(compareMode))
  const compareRangeLabel = comparisonRange
    ? formatAnalyticsRangeLabel(comparisonRange)
    : ''

  // ── Filters ──
  const { filters } = useAnalyticsFilters()

  // ── Data ──
  const {
    stats: statsFromHook,
    error: statsError,
    unsupported: statsUnsupported,
  } = useAnalyticsStats(projectId, propertyId, range, filters)
  // The loader's prefetch is unfiltered, so only use it without filters.
  const stats =
    statsFromHook ?? (filters.length === 0 ? fallbackStats : undefined)
  const { stats: comparisonStats } = useAnalyticsStats(
    projectId,
    comparePropertyId,
    compareRange,
    filters,
  )
  const previousStats = isComparing ? comparisonStats : undefined

  // Aggregate metrics can't be read with a page / event filter, so while one
  // is active the chart falls back to visitors (the tab explains why).
  const requestedMetric =
    OVERVIEW_METRICS.find((metric) => metric.key === activeSeries) ?? OVERVIEW_METRICS[0]
  const activeMetric =
    requestedMetric.source === 'aggregate' && statsUnsupported
      ? OVERVIEW_METRICS[0]
      : requestedMetric

  const {
    data: seriesFromHook,
    isLoading: seriesLoading,
    error: seriesError,
  } = useAnalyticsEventMetrics(
    projectId,
    propertyId,
    null,
    range,
    interval,
    filters,
  )
  // The loader's prefetch is unfiltered, so only use it without filters.
  const seriesData =
    seriesFromHook ?? (filters.length === 0 ? fallbackSeries : undefined)
  const { data: comparisonSeriesData } = useAnalyticsEventMetrics(
    projectId,
    comparePropertyId,
    null,
    compareRange,
    interval,
    filters,
  )
  const previousSeriesData = isComparing ? comparisonSeriesData : undefined

  const chartPoints = useMemo(
    () => buildAnalyticsChartPoints(seriesData?.points ?? [], range, interval),
    [seriesData, range, interval],
  )
  const previousPoints = useMemo(
    () =>
      previousSeriesData && comparisonRange
        ? buildAnalyticsChartPoints(
            previousSeriesData.points,
            comparisonRange,
            interval,
          )
        : [],
    [previousSeriesData, comparisonRange, interval],
  )

  // Pageviews per bucket = the series filtered to the pageview event. Only
  // fetched when it's needed: Pageviews is plotted, or the flat aggregate
  // can't give the total (page / event filter active).
  const needsPageviews = activeMetric.key === 'pageviews' || statsUnsupported
  const { data: pageviewSeriesData, error: pageviewError } =
    useAnalyticsEventMetrics(
      projectId,
      needsPageviews ? propertyId : null,
      ANALYTICS_PAGEVIEW_EVENT,
      range,
      interval,
      filters,
    )
  const { data: comparisonPageviewData } = useAnalyticsEventMetrics(
    projectId,
    needsPageviews && isComparing ? propertyId : null,
    ANALYTICS_PAGEVIEW_EVENT,
    compareRange,
    interval,
    filters,
  )
  const pageviewPoints = useMemo(
    () =>
      buildAnalyticsChartPoints(pageviewSeriesData?.points ?? [], range, interval),
    [pageviewSeriesData, range, interval],
  )
  const previousPageviewPoints = useMemo(
    () =>
      isComparing && comparisonPageviewData && comparisonRange
        ? buildAnalyticsChartPoints(comparisonPageviewData.points, comparisonRange, interval)
        : [],
    [isComparing, comparisonPageviewData, comparisonRange, interval],
  )

  // ── Aggregate-backed metrics: one flat read per bucket window ──
  // Only fetched while one of those metrics is plotted. Each window reuses
  // the stats query options, so windows are cached and refreshed like the
  // rest of the page.
  const plotsAggregate = activeMetric.source === 'aggregate'
  const windows = useMemo(
    () => buildBucketWindows(chartPoints, range),
    [chartPoints, range],
  )
  // The comparison grid is built from the range alone (no data needed), so
  // its windows exist before the comparison series loads.
  const comparisonGrid = useMemo(
    () => (comparisonRange ? buildAnalyticsChartPoints([], comparisonRange, interval) : []),
    [comparisonRange, interval],
  )
  const comparisonWindows = useMemo(
    () => (comparisonRange ? buildBucketWindows(comparisonGrid, comparisonRange) : []),
    [comparisonGrid, comparisonRange],
  )
  const windowResults = useQueries({
    queries: windows.map((window) =>
      analyticsStatsQueryOptions(
        projectId,
        plotsAggregate ? propertyId : null,
        window.range,
        filters,
      ),
    ),
  })
  const comparisonWindowResults = useQueries({
    queries: comparisonWindows.map((window) =>
      analyticsStatsQueryOptions(
        projectId,
        plotsAggregate && isComparing ? propertyId : null,
        window.range,
        filters,
      ),
    ),
  })
  const windowData = windowResults.map((result) => result.data)
  const comparisonWindowData = comparisonWindowResults.map((result) => result.data)
  const windowsSettled =
    windowResults.length > 0 &&
    windowResults.every((result) => result.data !== undefined || result.isError)
  const comparisonWindowsSettled =
    comparisonWindowResults.length === 0 ||
    comparisonWindowResults.every(
      (result) => result.data !== undefined || result.isError,
    )
  const windowsError = windowResults.find((result) => result.isError)?.error

  const hasSeries =
    activeMetric.source === 'aggregate'
      ? windowsSettled
      : (activeMetric.key === 'pageviews' ? pageviewSeriesData : seriesData) !==
        undefined
  const comparisonReady =
    !isComparing ||
    (activeMetric.source === 'aggregate'
      ? comparisonWindowsSettled
      : activeMetric.key === 'pageviews'
        ? comparisonPageviewData !== undefined
        : previousSeriesData !== undefined)
  // First paint waits for the current series and, when compare is on (the
  // default), the comparison series too. Otherwise the dashed previous-period
  // line lands from cache first, then the area remounts onto the full series.
  const bothReady = hasSeries && comparisonReady
  if (bothReady && !hasRevealed) {
    setHasRevealed(true)
  }
  const revealed = hasRevealed || bothReady
  const loadError =
    activeMetric.source === 'aggregate'
      ? (windowsError ?? statsError)
      : (statsError ?? (activeMetric.key === 'pageviews' ? pageviewError : seriesError))
  const showError = !!loadError && !hasSeries
  const showSkeleton = !showError && !revealed
  const showComparisonLine = isComparing && comparisonReady && !showSkeleton

  // The comparison window is aligned by bucket index, so the dashed line sits
  // under the matching hour / day of the current window. A custom window of a
  // different length simply runs short or is cut off.
  // Computed per render: `useQueries` returns fresh arrays each time, so a
  // memo would never hit, and the grid is small (≤ a few hundred points).
  const metricKey = activeMetric.key
  const currentValues: (number | null)[] =
    activeMetric.source === 'aggregate'
      ? windowValues(chartPoints.length, windows, windowData, metricKey as AggregateMetric)
      : chartPoints.map(
          (point, index) =>
            bucketValue(metricKey as SeriesMetric, point, pageviewPoints[index]) ?? 0,
        )
  const previousValues: (number | null)[] = !showComparisonLine
    ? []
    : activeMetric.source === 'aggregate'
      ? windowValues(
          comparisonGrid.length,
          comparisonWindows,
          comparisonWindowData,
          metricKey as AggregateMetric,
        )
      : previousPoints.map(
          (point, index) =>
            bucketValue(metricKey as SeriesMetric, point, previousPageviewPoints[index]) ??
            null,
        )
  const chartData = chartPoints.map((point, index) => ({
    ...point,
    value: showSkeleton
      ? SKELETON_WAVE[index % SKELETON_WAVE.length]
      : (currentValues[index] ?? null),
    previous: showComparisonLine ? (previousValues[index] ?? null) : undefined,
    previousFullDate: (previousPoints[index] ?? comparisonGrid[index])?.fullDate,
  }))

  // Tab numbers come from the property-wide flat aggregate, which
  // deduplicates visitors / visits across the whole window (summing a daily
  // series would count a returning visitor once per day). Summed buckets are
  // only a fallback while the aggregate loads, or when a filter rules it out.
  const sum = (points: AnalyticsChartPoint[], key: 'visitors' | 'sessions' | 'events') =>
    points.reduce((total, point) => total + point[key], 0)
  const metricTotal = (
    key: AnalyticsChartMetric,
    aggregate: Models.AnalyticsMetric | undefined,
    base: AnalyticsChartPoint[],
    pageviews: AnalyticsChartPoint[],
    hasBase: boolean,
    hasPageviews: boolean,
  ): number | undefined => {
    if (aggregate) return aggregate[key] ?? 0
    if (key === 'visitors') return hasBase ? sum(base, 'visitors') : undefined
    if (key === 'visits') return hasBase ? sum(base, 'sessions') : undefined
    if (key === 'pageviews') return hasPageviews ? sum(pageviews, 'events') : undefined
    return undefined // bounce rate / duration only exist on the aggregate
  }

  const isEmpty =
    !showSkeleton && hasSeries && chartData.every((point) => !point.value)

  // ── Chart helpers ──
  const brushPoints = useMemo(
    () => chartPoints.map((point) => ({ date: point.date, day: point.day })),
    [chartPoints],
  )
  const {
    canSelect,
    isSelecting,
    brushLeft,
    brushRight,
    surfaceClassName,
    chartProps,
  } = useUsageChartBrushSelect({
    points: brushPoints,
    chartInterval: interval,
    onDateRangeChange,
  })

  const axisMax = chartData.reduce(
    (max, point) => Math.max(max, point.value ?? 0, point.previous ?? 0),
    0,
  )
  const yAxisTickFormatter = axisFormatter(activeMetric.axis, axisMax)

  const activeSeriesLabel = t(activeMetric.label)

  // ── Metric tabs ──
  const tabs = OVERVIEW_METRICS.map((metric) => {
    const value = metricTotal(
      metric.key,
      stats,
      chartPoints,
      pageviewPoints,
      seriesData !== undefined,
      pageviewSeriesData !== undefined,
    )
    const previous = isComparing
      ? metricTotal(
          metric.key,
          previousStats,
          previousPoints,
          previousPageviewPoints,
          previousSeriesData !== undefined,
          comparisonPageviewData !== undefined,
        )
      : undefined
    // Aggregate-only metrics can't be shown with a page / event filter; say
    // so rather than quietly showing unfiltered numbers.
    const blockedByFilter = metric.source === 'aggregate' && statsUnsupported
    return {
      ...metric,
      value,
      change: isComparing ? analyticsChangePercent(value, previous) : undefined,
      unavailable: value === undefined,
      hint: blockedByFilter ? t(ANALYTICS_FILTER_UNSUPPORTED_MESSAGE) : undefined,
      blockedByFilter,
    }
  })

  return (
    <div className="w-full">
      {/* Active filters, removable in place. Hidden when none. */}
      <ActiveFilterChips />

      {/* Series totals. Filters, interval, date range, comparison and
          refresh live in the standard ServiceHeader toolbar above. */}
      <div className="border-b border-border px-4 py-3 sm:px-6">
        {/* One row of seven on wide screens; wraps to 4 / 2 columns. */}
        <div className="-mx-3 grid min-w-0 grid-cols-2 gap-1 sm:grid-cols-4 xl:grid-cols-7">
          {tabs.map((tab) => (
            <MetricTab
              key={tab.key}
              label={t(tab.label)}
              info={tab.info}
              value={tab.value}
              format={tab.format}
              change={tab.change}
              invert={tab.invert}
              isActive={activeMetric.key === tab.key}
              unavailable={tab.unavailable}
              hint={tab.hint}
              onClick={
                tab.blockedByFilter ? undefined : () => onActiveSeriesChange(tab.key)
              }
            />
          ))}
        </div>
      </div>

      {/* Chart */}
      <div className="border-b border-border px-4 pb-4 pt-4 sm:px-6">
        <div className="mb-3 flex min-h-5 flex-wrap items-center justify-between gap-x-4 gap-y-2">
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div className="flex items-center gap-1.5">
              <span
                className="h-2 w-2 rounded-full"
                style={{ backgroundColor: CURRENT_COLOR }}
              />
              <span className="text-[12px] text-muted-foreground">
                {activeSeriesLabel}
              </span>
            </div>
            {showComparisonLine ? (
              <div className="flex items-center gap-1.5">
                <span
                  className="h-0 w-3 border-t-2 border-dashed"
                  style={{ borderColor: PREVIOUS_COLOR }}
                />
                <span className="text-[12px] text-muted-foreground">
                  {compareLabel}
                  {compareRangeLabel ? (
                    <span className="text-muted-foreground/70">
                      {' '}
                      · {compareRangeLabel}
                    </span>
                  ) : null}
                </span>
              </div>
            ) : null}
          </div>
        </div>

        <ChartArea>
          {showError ? (
            <div className={overviewChartPanelErrorClass}>
              <p className="text-[13px] font-medium text-foreground">
                {t('Could not load analytics data')}
              </p>
              <p className="max-w-md text-[12px] text-muted-foreground">
                {loadError instanceof Error
                  ? loadError.message
                  : t('Something went wrong')}
              </p>
              <Button variant="outline" size="sm" onClick={onRefresh}>
                {t('Try again')}
              </Button>
            </div>
          ) : (
            <div
              key={showSkeleton ? 'skeleton' : 'data'}
              className={cn(
                surfaceClassName,
                'relative',
                showSkeleton && 'pointer-events-none',
                !showSkeleton && USAGE_CHART_FADE_IN_CLASS_NAME,
              )}
              aria-busy={showSkeleton || undefined}
              aria-label={
                showSkeleton
                  ? t('Loading analytics data')
                  : canSelect
                    ? t('Drag on the chart to select a date range')
                    : undefined
              }
            >
              <ResponsiveContainer
                {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}
                minHeight={OVERVIEW_CHART_HEIGHT}
              >
                <ComposedChart
                  data={chartData}
                  margin={{ top: 8, right: 12, left: 0, bottom: 4 }}
                  {...chartProps}
                >
                  <defs>
                    {showSkeleton ? (
                      <linearGradient
                        id={SKELETON_GRADIENT_ID}
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                      >
                        <stop
                          offset="0%"
                          stopColor={SKELETON_CHART_FILL}
                          stopOpacity={0.14}
                        />
                        <stop
                          offset="100%"
                          stopColor={SKELETON_CHART_FILL}
                          stopOpacity={0.02}
                        />
                      </linearGradient>
                    ) : (
                      <linearGradient
                        id={GRADIENT_ID}
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                      >
                        <stop
                          offset="0%"
                          stopColor={CURRENT_COLOR}
                          stopOpacity={0.2}
                        />
                        <stop
                          offset="100%"
                          stopColor={CURRENT_COLOR}
                          stopOpacity={0}
                        />
                      </linearGradient>
                    )}
                  </defs>
                  <CartesianGrid
                    strokeDasharray="3 3"
                    stroke="var(--border)"
                    vertical={false}
                  />
                  <UsageChartXAxis
                    points={brushPoints}
                    dateRange={dateRange}
                    chartInterval={interval}
                  />
                  <UsageChartYAxis
                    tickFormatter={yAxisTickFormatter}
                    allowDecimals={activeMetric.axis !== 'count'}
                    domain={[
                      0,
                      (dataMax: number) => Math.ceil(dataMax * 1.08) || 1,
                    ]}
                  />
                  <Tooltip
                    {...CHART_ANIMATION_DISABLED}
                    cursor={!showSkeleton && !isSelecting}
                    content={({ active, payload }) => {
                      if (
                        showSkeleton ||
                        isSelecting ||
                        !active ||
                        !payload?.length
                      ) {
                        return null
                      }
                      const point = payload[0]?.payload as
                        | (typeof chartData)[number]
                        | undefined
                      if (!point) return null
                      return (
                        <div className="min-w-[180px] rounded-md border border-border bg-popover px-3 py-2">
                          <p className="mb-1.5 text-[11px] text-muted-foreground">
                            {point.fullDate}
                          </p>
                          <div className="flex items-center justify-between gap-6">
                            <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                              <ChartSeriesDot color={CURRENT_COLOR} />
                              {activeSeriesLabel}
                            </span>
                            <span className="text-[13px] font-medium tabular-nums text-foreground">
                              {point.value == null ? '–' : activeMetric.format(point.value)}
                            </span>
                          </div>
                          {showComparisonLine && point.previous != null ? (
                            <>
                              <div className="mt-1 flex items-center justify-between gap-6">
                                <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                  <span
                                    className="h-0 w-2 border-t-2 border-dashed"
                                    style={{ borderColor: PREVIOUS_COLOR }}
                                  />
                                  {point.previousFullDate ?? compareLabel}
                                </span>
                                <span className="text-[13px] tabular-nums text-muted-foreground">
                                  {activeMetric.format(point.previous)}
                                </span>
                              </div>
                              <div className="mt-1.5 flex justify-end border-t border-border pt-1.5">
                                <ChangeBadge
                                  change={analyticsChangePercent(
                                    point.value,
                                    point.previous,
                                  )}
                                  invert={activeMetric.invert}
                                />
                              </div>
                            </>
                          ) : null}
                        </div>
                      )
                    }}
                  />
                  {showComparisonLine ? (
                    <Line
                      type="monotone"
                      dataKey="previous"
                      connectNulls
                      stroke={PREVIOUS_COLOR}
                      strokeOpacity={0.5}
                      strokeWidth={1.5}
                      strokeDasharray="4 4"
                      dot={false}
                      activeDot={false}
                      {...CHART_ANIMATION_DISABLED}
                    />
                  ) : null}
                  <Area
                    type="monotone"
                    dataKey="value"
                    // Grouped aggregate windows leave gaps between points.
                    connectNulls
                    stroke={showSkeleton ? SKELETON_CHART_STROKE : CURRENT_COLOR}
                    strokeWidth={2}
                    fill={
                      showSkeleton
                        ? `url(#${SKELETON_GRADIENT_ID})`
                        : `url(#${GRADIENT_ID})`
                    }
                    dot={false}
                    activeDot={
                      showSkeleton
                        ? false
                        : {
                            r: 4,
                            fill: CURRENT_COLOR,
                            stroke: 'var(--background)',
                            strokeWidth: 2,
                          }
                    }
                    {...CHART_ANIMATION_DISABLED}
                  />
                  <UsageChartBrushReferenceArea
                    left={brushLeft}
                    right={brushRight}
                  />
                </ComposedChart>
              </ResponsiveContainer>
              {isEmpty && !seriesLoading ? (
                <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                  <p className="text-[13px] font-medium text-muted-foreground">
                    {t('No data in this range')}
                  </p>
                  <p className="mt-1 text-[12px] text-muted-foreground/70">
                    {t('Try a wider date range or fewer filters.')}
                  </p>
                </div>
              ) : null}
            </div>
          )}
        </ChartArea>
      </div>
    </div>
  )
}
