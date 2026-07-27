import { useEffect, useMemo, useRef, useState } from 'react'
import { cn } from '@/lib/utils'
import { ArrowLeft, BarChart3 } from 'lucide-react'
import {
  ServiceHeader,
  type Tab,
} from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  createCompactCountAxisTickFormatter,
  getChartSeriesMax,
} from '@/lib/usage/format-metric'
import { USAGE_CHART_Y_AXIS_WIDTH } from '../../overview/chart-panel'
import { USAGE_CHART_MARGIN } from '@/lib/usage/chart-layout'
import { SeriesChartXAxis } from '@/components/global/shared/ChartXAxis'
import { Area, AreaChart, ResponsiveContainer, Tooltip, YAxis } from 'recharts'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'
import {
  ANALYTICS_PAGEVIEW_EVENT,
  DEFAULT_ANALYTICS_DATE_RANGE,
  DEFAULT_ANALYTICS_RANGE,
  EMPTY_ANALYTICS_METRIC,
  useAnalyticsEventMetrics,
  useAnalyticsEvents,
  useAnalyticsProperty,
  useAnalyticsStats,
  useOrganizationScopes,
  useProject,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canCreateAnalyticsProperty } from '@/lib/console-access-checks'
import { DateRangeSelect } from '../_components/DateRangeSelect'
import { PropertySettings } from '../_components/PropertySettings'
import {
  formatDuration,
  formatNumber,
  formatPercent,
  formatRatio,
} from '../_components/format'

/**
 * Time series returned by `getEventMetrics` only carries these three series,
 * so only these metrics are chartable. The remaining aggregates from
 * `getStats` are rendered as summary tiles instead.
 */
type ChartSeriesKey = 'visitors' | 'sessions' | 'events'

type ChartMetric = {
  id: ChartSeriesKey
  label: string
  value: number
}

export type PropertyDetailInitialData = {
  property: Models.AnalyticsProperty
  stats?: Models.AnalyticsMetric
  events?: { events: Models.AnalyticsEvent[]; total: number }
  series?: { points: Models.AnalyticsMetricPoint[]; total: number }
}

interface ViewProps {
  projectId: string
  propertyId: string
  onBack?: () => void
  initialData?: PropertyDetailInitialData
}

/**
 * Series points are `YYYY-MM-DD` day buckets, not instants. Parse as a plain
 * calendar date so the label never shifts a day across timezones.
 */
function formatChartDate(value: string): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return value
  const [, year, month, day] = match
  const parsed = new Date(Number(year), Number(month) - 1, Number(day))
  if (Number.isNaN(parsed.getTime())) return value
  return parsed.toLocaleDateString(undefined, {
    day: 'numeric',
    month: 'short',
  })
}

// Chart tooltip
interface ChartTooltipProps {
  active?: boolean
  payload?: Array<{
    value: number
    payload: { label: string; value: number }
  }>
}

function CustomTooltip({ active, payload }: ChartTooltipProps) {
  if (!active || !payload || payload.length === 0) return null
  const point = payload[0].payload
  return (
    <div className="rounded-lg border border-border bg-popover px-3 py-2">
      <p className="text-[13px] font-medium text-foreground">
        {point.label} {formatNumber(point.value)}
      </p>
    </div>
  )
}

function MetricTab({
  metric,
  isActive,
  onClick,
}: {
  metric: ChartMetric
  isActive: boolean
  onClick: () => void
}) {
  const t = useT()
  return (
    <button
      onClick={onClick}
      className={cn(
        'relative flex min-w-[140px] flex-col gap-0.5 px-3 py-2.5 text-start transition-colors',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        isActive
          ? 'text-foreground'
          : 'text-muted-foreground hover:text-foreground/80',
      )}
    >
      <span
        className={cn(
          'text-[18px] font-semibold tracking-tight tabular-nums',
          isActive ? 'text-foreground' : 'text-muted-foreground',
        )}
      >
        {formatNumber(metric.value)}
      </span>
      <span
        className={cn(
          'text-[11px] font-medium',
          isActive ? 'text-muted-foreground' : 'text-muted-foreground/70',
        )}
      >
        {t(metric.label)}
      </span>
      {isActive && (
        <div className="absolute bottom-0 start-0 end-0 h-[2px] bg-foreground" />
      )}
    </button>
  )
}

function SummaryTile({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg bg-muted/30 px-3 py-2">
      <p className="text-[11px] text-muted-foreground">{label}</p>
      <span className="text-[14px] font-semibold tabular-nums text-foreground">
        {value}
      </span>
    </div>
  )
}

export function View({
  projectId,
  propertyId,
  onBack,
  initialData,
}: ViewProps) {
  const t = useT()
  const [activeTab, setActiveTab] = useState('analytics')
  const [activeMetric, setActiveMetric] = useState<ChartSeriesKey>('visitors')
  const [range, setRange] = useState<AnalyticsRange>(DEFAULT_ANALYTICS_RANGE)

  const { property: propertyFromHook, isLoading: propertyLoading } =
    useAnalyticsProperty(projectId, propertyId)
  const property = propertyFromHook ?? initialData?.property

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canCreateAnalyticsProperty(access, features)

  const isDefaultRange =
    range.kind === 'shorthand' &&
    range.dateRange === DEFAULT_ANALYTICS_DATE_RANGE

  const { stats: statsFromHook, error: statsError } = useAnalyticsStats(
    projectId,
    propertyId,
    range,
  )
  const stats =
    statsFromHook ??
    (isDefaultRange ? initialData?.stats : undefined) ??
    EMPTY_ANALYTICS_METRIC

  const { events: eventsFromHook, isLoading: eventsLoading } =
    useAnalyticsEvents(projectId, propertyId, range)
  const events = useMemo(() => {
    if (eventsFromHook.length > 0) return eventsFromHook
    if (isDefaultRange) return initialData?.events?.events ?? []
    return []
  }, [eventsFromHook, isDefaultRange, initialData])

  const { points: pointsFromHook } = useAnalyticsEventMetrics(
    projectId,
    propertyId,
    ANALYTICS_PAGEVIEW_EVENT,
    range,
  )
  const points = useMemo(() => {
    if (pointsFromHook.length > 0) return pointsFromHook
    if (isDefaultRange) return initialData?.series?.points ?? []
    return []
  }, [pointsFromHook, isDefaultRange, initialData])

  // Chart container needs measurable dimensions before recharts renders.
  const chartContainerRef = useRef<HTMLDivElement>(null)
  const [chartHasDimensions, setChartHasDimensions] = useState(false)

  useEffect(() => {
    const element = chartContainerRef.current
    if (!element) return

    const checkDimensions = () => {
      const rect = element.getBoundingClientRect()
      const computedStyle = window.getComputedStyle(element)
      setChartHasDimensions(
        computedStyle.display !== 'none' &&
          computedStyle.visibility !== 'hidden' &&
          element.offsetParent !== null &&
          rect.width > 0 &&
          rect.height > 0,
      )
    }

    const timeout = setTimeout(checkDimensions, 50)
    const observer = new ResizeObserver(checkDimensions)
    observer.observe(element)

    return () => {
      clearTimeout(timeout)
      observer.disconnect()
    }
  }, [activeTab])

  const chartMetrics: ChartMetric[] = useMemo(
    () => [
      { id: 'visitors', label: 'Unique visitors', value: stats.visitors },
      { id: 'sessions', label: 'Visits', value: stats.visits },
      { id: 'events', label: 'Pageviews', value: stats.pageviews },
    ],
    [stats],
  )

  const chartData = useMemo(
    () =>
      points.map((point) => ({
        label: formatChartDate(point.date),
        value: point[activeMetric],
      })),
    [points, activeMetric],
  )

  const yAxisTickFormatter = useMemo(
    () =>
      createCompactCountAxisTickFormatter(
        getChartSeriesMax(chartData.map((point) => ({ value: point.value }))),
      ),
    [chartData],
  )

  const maxEventCount = useMemo(
    () => events.reduce((max, event) => Math.max(max, event.count), 0),
    [events],
  )

  const tabs: Tab[] = [
    { id: 'analytics', label: t('Analytics') },
    { id: 'settings', label: t('Settings') },
  ]

  if (!property && !propertyLoading) {
    return (
      <div className="flex flex-col">
        <ServiceHeader
          title={t('Analytics')}
          showFilters={false}
          fullWidthBorder
        />
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 py-4 sm:px-6">
          <EmptyState
            icon={BarChart3}
            title={t('Property not found')}
            description={t('This analytics property no longer exists.')}
            variant="card"
            iconSize="md"
          />
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <div className="flex min-w-0 items-center gap-2">
            {onBack && (
              <Button
                variant="ghost"
                size="sm"
                className="h-7 w-7 p-0"
                onClick={onBack}
              >
                <ArrowLeft className="h-4 w-4" />
              </Button>
            )}
            <span className="truncate">{property?.name ?? t('Analytics')}</span>
            <CopyableId id={propertyId} size="xs" className="shrink-0" />
            {property && !property.enabled && (
              <Badge variant="warning" className="text-[10px] shrink-0">
                {t('Disabled')}
              </Badge>
            )}
          </div>
        }
        tabs={tabs}
        activeTab={activeTab}
        onTabChange={setActiveTab}
        showFilters={false}
        fullWidthBorder
      />

      <div className="flex-1 flex flex-col">
        <div className="mx-auto w-full max-w-7xl flex-1">
          {activeTab === 'analytics' && (
            <div className="px-4 py-4 sm:px-6">
              {/* Date range */}
              <div className="mb-3 flex items-center justify-between">
                <span className="text-[12px] font-medium text-muted-foreground">
                  {property?.domain || t('No domain')}
                </span>
                <DateRangeSelect value={range} onChange={setRange} />
              </div>

              {statsError ? (
                <EmptyState
                  icon={BarChart3}
                  title={t('Could not load analytics data')}
                  description={
                    statsError instanceof Error
                      ? statsError.message
                      : t('Something went wrong')
                  }
                  variant="card"
                  iconSize="md"
                />
              ) : (
                <>
                  {/* Metrics and chart */}
                  <div className="rounded-lg border border-border bg-card">
                    <div className="border-b border-border px-2">
                      <div className="flex overflow-x-auto overflow-y-hidden">
                        {chartMetrics.map((metric, index) => (
                          <div key={metric.id} className="flex shrink-0">
                            {index > 0 && (
                              <div className="my-2 w-px bg-border" />
                            )}
                            <MetricTab
                              metric={metric}
                              isActive={activeMetric === metric.id}
                              onClick={() => setActiveMetric(metric.id)}
                            />
                          </div>
                        ))}
                      </div>
                    </div>

                    <div className="p-4">
                      <div
                        ref={chartContainerRef}
                        className="h-[280px] w-full text-muted-foreground"
                      >
                        {chartData.length === 0 ? (
                          <div className="flex h-full flex-col items-center justify-center text-center">
                            <p className="text-[13px] font-medium text-muted-foreground">
                              {t('No data yet')}
                            </p>
                            <p className="mt-1 text-[12px] text-muted-foreground/70">
                              {t(
                                'Data appears once the property receives its first event.',
                              )}
                            </p>
                          </div>
                        ) : chartHasDimensions &&
                          typeof window !== 'undefined' ? (
                          <ResponsiveContainer
                            width="100%"
                            height="100%"
                            minWidth={0}
                            minHeight={0}
                          >
                            <AreaChart
                              data={chartData}
                              margin={USAGE_CHART_MARGIN}
                            >
                              <defs>
                                <linearGradient
                                  id="analyticsVisitorGradient"
                                  x1="0"
                                  y1="0"
                                  x2="0"
                                  y2="1"
                                >
                                  <stop
                                    offset="0%"
                                    stopColor="var(--chart-brand)"
                                    stopOpacity={0.15}
                                  />
                                  <stop
                                    offset="100%"
                                    stopColor="var(--chart-brand)"
                                    stopOpacity={0}
                                  />
                                </linearGradient>
                              </defs>
                              <SeriesChartXAxis
                                pointCount={chartData.length}
                                dataKey="label"
                                tick={{ fill: 'currentColor', fontSize: 12 }}
                              />
                              <YAxis
                                axisLine={false}
                                tickLine={false}
                                tick={{ fill: 'currentColor', fontSize: 12 }}
                                tickFormatter={yAxisTickFormatter}
                                dx={-5}
                                width={USAGE_CHART_Y_AXIS_WIDTH}
                              />
                              <Tooltip
                                content={<CustomTooltip />}
                                cursor={false}
                              />
                              <Area
                                type="monotone"
                                dataKey="value"
                                stroke="var(--chart-brand)"
                                strokeWidth={2}
                                fill="url(#analyticsVisitorGradient)"
                                dot={false}
                                activeDot={{
                                  r: 5,
                                  fill: 'var(--chart-brand)',
                                  stroke: '#fff',
                                  strokeWidth: 2,
                                }}
                              />
                            </AreaChart>
                          </ResponsiveContainer>
                        ) : null}
                      </div>
                    </div>
                  </div>

                  {/* Engagement summary */}
                  <div className="mt-4 rounded-lg border border-border bg-card">
                    <div className="border-b border-border px-4 py-2.5">
                      <h3 className="text-[13px] font-semibold text-foreground">
                        {t('Engagement')}
                      </h3>
                    </div>
                    <div className="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-4">
                      <SummaryTile
                        label={t('Bounce rate')}
                        value={formatPercent(stats.bounceRate)}
                      />
                      <SummaryTile
                        label={t('Visit duration')}
                        value={formatDuration(stats.visitDuration)}
                      />
                      <SummaryTile
                        label={t('Views per visit')}
                        value={formatRatio(stats.viewsPerVisit)}
                      />
                      <SummaryTile
                        label={t('Scroll depth')}
                        value={formatPercent(stats.scrollDepth)}
                      />
                    </div>
                  </div>

                  {/* Events */}
                  <div className="mt-4 rounded-lg border border-border bg-card">
                    <div className="border-b border-border px-4 py-2.5">
                      <h3 className="text-[13px] font-semibold text-foreground">
                        {t('Events')}
                      </h3>
                      <p className="mt-0.5 text-[11px] text-muted-foreground">
                        {t('Distinct events recorded in the selected range')}
                      </p>
                    </div>
                    <div className="p-4">
                      {events.length === 0 ? (
                        <p className="py-6 text-center text-[13px] text-muted-foreground">
                          {eventsLoading
                            ? ''
                            : t('No events recorded in this range')}
                        </p>
                      ) : (
                        <div className="space-y-3">
                          <div className="flex items-center justify-between text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            <span>{t('Event')}</span>
                            <span>{t('Count')}</span>
                          </div>
                          {events.map((event) => {
                            const percentage =
                              maxEventCount > 0
                                ? (event.count / maxEventCount) * 100
                                : 0
                            return (
                              <div key={event.name} className="space-y-1.5">
                                <div className="flex items-center justify-between gap-4">
                                  <span className="truncate text-[12px] font-medium text-foreground">
                                    {event.name}
                                  </span>
                                  <div className="flex shrink-0 items-center gap-3">
                                    <span className="text-[11px] text-muted-foreground tabular-nums">
                                      {formatNumber(event.visitors)}{' '}
                                      {t('visitors')}
                                    </span>
                                    <span className="text-[12px] font-semibold tabular-nums text-foreground">
                                      {formatNumber(event.count)}
                                    </span>
                                  </div>
                                </div>
                                <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                  <div
                                    className="h-full transition-all"
                                    style={{
                                      width: `${percentage}%`,
                                      backgroundColor: 'var(--chart-brand)',
                                    }}
                                  />
                                </div>
                              </div>
                            )
                          })}
                        </div>
                      )}
                    </div>
                  </div>
                </>
              )}
            </div>
          )}

          {activeTab === 'settings' && property && (
            <PropertySettings
              projectId={projectId}
              property={property}
              canWrite={canWrite}
            />
          )}
        </div>
      </div>
    </div>
  )
}
