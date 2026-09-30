import { useCallback, useMemo, useState, type ReactNode } from 'react'
import { useParams } from '@tanstack/react-router'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import { cn } from '@/lib/utils'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { createCompactCountAxisTickFormatter } from '@/lib/usage/format-metric'
import { useChartLiveUpdateAnimation } from '@/lib/usage/chart-animation'
import { USAGE_CHART_RESPONSIVE_CONTAINER_PROPS } from '@/lib/usage/chart-layout'
import { USAGE_CHART_FADE_IN_CLASS_NAME } from '@/lib/usage/usage-chart-loading'
import {
  getUsageChartIntervalsForPlan,
  resolveUsageChartIntervalForRange,
} from '@/lib/usage/chart-interval'
import {
  getUsageLogRetentionDaysFromPlan,
  getUsageLogRetentionHoursFromPlan,
  hasFiniteUsageLogRetention,
  resolveShorterUsageDateRangePreset,
} from '@/lib/usage/usage-log-retention'
import {
  UsageChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import { AnimatedCounter } from '@/components/global/shared/AnimatedCounter'
import { UsageChartIntervalToggle } from '../overview/UsageChartIntervalToggle'
import {
  OVERVIEW_CHART_HEIGHT,
  overviewChartPanelBodyClass,
  overviewChartPanelChartAreaClass,
  overviewChartPanelChartFillClass,
} from '../overview/chart-panel'
import {
  useOrganizationPlan,
  useProject,
  useProjectFirewallTrafficOverview,
} from '@/lib/react-query/hooks'
import { useUsageChartFilters } from '@/hooks/use-usage-chart-filters'
import { useDocumentVisible } from '@/hooks/use-document-visible'
import { useFirewallTrafficLiveUpdatesEnabled } from '@/hooks/use-firewall-traffic-live-updates-pref'
import { useFirewallTrafficLivePolling } from '@/hooks/use-firewall-traffic-live-polling'
import { useFirewallTrafficLiveTransitions } from '@/hooks/use-firewall-traffic-live-transitions'
import { useUsageChartBrushSelect } from '@/hooks/use-usage-chart-brush'
import { FirewallChartLiveControls } from './_components/FirewallChartLiveControls'
import { FirewallChartLiveIndicator } from './_components/FirewallChartLiveIndicator'
import {
  FIREWALL_TRAFFIC_FETCH_ERROR,
  FirewallUsageChartError,
} from './_components/FirewallUsageChartError'
import { useUsageHistoryLimitAlertState } from '@/hooks/use-usage-history-limit-alert'
import {
  FIREWALL_TRAFFIC_SERIES,
  sortFirewallTrafficSeriesByValueAsc,
  type FirewallTrafficSeriesKey,
} from '@/lib/firewall/traffic-series'
import { formatFirewallSolveTime } from '@/lib/firewall/usage'
import { UsageLogRetentionAlert } from '../usage/_components/UsageLogRetentionAlert'
import { UsageChartBrushReferenceArea } from '../usage/_components/UsageChartBrushReferenceArea'
import { useT } from '@/lib/i18n/translate'
import type { FirewallResourceSelection } from '@/lib/firewall/conditions'

interface MetricSubStat {
  /** Short unit/label shown inline after the value. */
  label: string
  /** Full name used for the hover title. */
  title: string
  value: number
  change?: number
  trend?: 'up' | 'down'
  decimals?: number
  suffix?: string
  formatValue?: (value: number) => string
}

interface StatCardProps {
  label: string
  value: number
  change?: number
  trend?: 'up' | 'down'
  subStats?: MetricSubStat[]
  decimals?: number
  suffix?: string
  formatValue?: (value: number) => string
  unavailable?: boolean
}

function MetricChange({
  change,
  trend,
}: {
  change?: number
  trend?: 'up' | 'down'
}) {
  if (change === undefined) return null
  return (
    <span
      className={cn(
        'text-[12px] font-medium tabular-nums',
        trend === 'up' && 'text-emerald-600 dark:text-emerald-400',
        trend === 'down' && 'text-amber-600 dark:text-amber-400',
        !trend && 'text-muted-foreground',
      )}
    >
      {change > 0 ? '+' : ''}
      {change}%
    </span>
  )
}

function MetricTile({
  label,
  value,
  change,
  trend,
  subStats,
  decimals,
  suffix,
  formatValue,
  unavailable,
}: StatCardProps) {
  return (
    <div className="min-w-0">
      <div className="flex min-w-0 items-baseline justify-between gap-x-2">
        <p className="min-w-0 truncate text-[12px] text-muted-foreground">
          {label}
        </p>
        {subStats && subStats.length > 0 && !unavailable ? (
          <p className="shrink-0 text-end text-[11px] leading-tight text-muted-foreground">
            {subStats.map((sub, index) => {
              const formattedValue = sub.formatValue
                ? sub.formatValue(sub.value)
                : sub.decimals !== undefined
                  ? sub.value.toLocaleString(undefined, {
                      minimumFractionDigits: sub.decimals,
                      maximumFractionDigits: sub.decimals,
                    }) + (sub.suffix ?? '')
                  : sub.value.toLocaleString() + (sub.suffix ?? '')
              const changeSuffix =
                sub.change !== undefined
                  ? ` (${sub.change > 0 ? '+' : ''}${sub.change}%)`
                  : ''
              return (
                <span
                  key={sub.title}
                  className="inline-flex items-baseline gap-x-1"
                  title={`${sub.title}: ${formattedValue}${changeSuffix}`}
                >
                  {index > 0 ? (
                    <span className="ms-1.5" aria-hidden="true">
                      ·
                    </span>
                  ) : null}
                  <AnimatedCounter
                    value={sub.value}
                    className="font-medium text-foreground"
                    decimals={sub.decimals}
                    suffix={sub.suffix}
                    formatDisplay={sub.formatValue}
                  />
                  {sub.label ? <span>{sub.label}</span> : null}
                </span>
              )
            })}
          </p>
        ) : null}
      </div>
      <div className="mt-0.5 flex min-w-0 items-baseline gap-x-2">
        {unavailable ? (
          <span className="text-[20px] font-semibold text-muted-foreground">
            -
          </span>
        ) : (
          <>
            <AnimatedCounter
              value={value}
              className={cn(
                'text-[20px] font-semibold text-foreground',
                USAGE_CHART_FADE_IN_CLASS_NAME,
              )}
              decimals={decimals}
              suffix={suffix}
              formatDisplay={formatValue}
            />
            <MetricChange change={change} trend={trend} />
          </>
        )}
      </div>
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

function changeTrend(change: number): 'up' | 'down' | undefined {
  if (change > 0) return 'up'
  if (change < 0) return 'down'
  return undefined
}

type TrafficOverviewProps = {
  resourceSelection: FirewallResourceSelection
}

export function TrafficOverview({ resourceSelection }: TrafficOverviewProps) {
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const usageLogRetentionHours = useMemo(
    () => getUsageLogRetentionHoursFromPlan(organizationPlan),
    [organizationPlan],
  )
  const {
    dateRange,
    chartInterval,
    dateRangePresetId,
    setDateRange,
    setChartInterval,
    refreshRollingDateRange,
  } = useUsageChartFilters(organizationPlan, usageLogRetentionHours)
  const usageLogRetentionDays = useMemo(
    () => getUsageLogRetentionDaysFromPlan(organizationPlan),
    [organizationPlan],
  )
  const planChartIntervals = useMemo(
    () => getUsageChartIntervalsForPlan(organizationPlan),
    [organizationPlan],
  )
  const resolvedChartInterval = useMemo(
    () =>
      resolveUsageChartIntervalForRange(
        chartInterval,
        dateRange,
        organizationPlan,
      ),
    [chartInterval, dateRange, organizationPlan],
  )

  const { showAlert: showUsageHistoryLimitAlert } =
    useUsageHistoryLimitAlertState({
      projectId,
      dateRange,
      dateRangePresetId,
      retentionHours: usageLogRetentionHours,
      organizationPlan,
    })

  const handleAdjustUsageDateRange = useCallback(() => {
    const fallbackPreset = resolveShorterUsageDateRangePreset(
      usageLogRetentionHours,
    )
    if (fallbackPreset) {
      setDateRange(fallbackPreset.getRange())
    }
  }, [setDateRange, usageLogRetentionHours])

  const isDocumentVisible = useDocumentVisible()
  const { liveUpdatesEnabled, toggleLiveUpdatesEnabled } =
    useFirewallTrafficLiveUpdatesEnabled()

  const {
    data: overview,
    refetch,
    isError,
    error,
  } = useProjectFirewallTrafficOverview(
    projectId,
    dateRange,
    resolvedChartInterval,
    usageLogRetentionHours,
    resourceSelection.resourceType,
    resourceSelection.resourceId,
    dateRangePresetId,
  )
  const [isRefreshing, setIsRefreshing] = useState(false)

  const { isLiveActive } = useFirewallTrafficLivePolling({
    enabled: liveUpdatesEnabled,
    isDocumentVisible,
    refreshRollingDateRange,
    refetch,
  })

  const { chartAnimationRevision } = useFirewallTrafficLiveTransitions({
    overview,
    enabled: liveUpdatesEnabled,
  })

  const chartLiveAnimation = useChartLiveUpdateAnimation(
    chartAnimationRevision,
    liveUpdatesEnabled,
  )

  const handleRefresh = async () => {
    setIsRefreshing(true)
    refreshRollingDateRange()
    try {
      await refetch()
    } finally {
      setIsRefreshing(false)
    }
  }

  const chartData = overview?.chartPoints ?? []
  const totalRequests = overview?.totalRequests ?? 0
  const totalPassed = overview?.totalPassed ?? 0
  const totalDenied = overview?.totalDenied ?? 0
  const totalChallenged = overview?.totalChallenged ?? 0
  const totalRateLimited = overview?.totalRateLimited ?? 0
  const totalRedirected = overview?.totalRedirected ?? 0
  const totalChallengeSolved = overview?.totalChallengeSolved ?? 0
  const avgSolveTimeMs = overview?.avgSolveTimeMs ?? 0
  const requestsChange = overview?.requestsChange ?? 0
  const passedChange = overview?.passedChange ?? 0
  const deniedChange = overview?.deniedChange ?? 0
  const challengedChange = overview?.challengedChange ?? 0
  const rateLimitedChange = overview?.rateLimitedChange ?? 0
  const redirectedChange = overview?.redirectedChange ?? 0
  const challengeSolvedChange = overview?.challengeSolvedChange ?? 0
  const avgSolveTimeChange = overview?.avgSolveTimeChange ?? 0
  const blockRateChange = overview?.blockRateChange ?? 0

  const chartPoints = useMemo(
    () => chartData.map((point) => ({ date: point.date, day: point.day })),
    [chartData],
  )
  const {
    canSelect,
    isSelecting,
    brushLeft,
    brushRight,
    surfaceClassName,
    chartProps,
  } = useUsageChartBrushSelect({
    points: chartPoints,
    chartInterval: resolvedChartInterval,
    onDateRangeChange: setDateRange,
  })

  // Series are stacked, so the axis max is the per-point sum of all series.
  const chartAxisMax = useMemo(
    () =>
      chartData.reduce(
        (max, point) =>
          Math.max(
            max,
            point.requests +
              point.denied +
              point.challenged +
              point.rateLimited +
              point.redirected,
          ),
        0,
      ),
    [chartData],
  )

  const yAxisTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(chartAxisMax),
    [chartAxisMax],
  )

  const blockRateValue =
    totalRequests > 0
      ? ((totalDenied + totalRateLimited) / totalRequests) * 100
      : 0

  const seriesTotals = useMemo(
    () =>
      ({
        requests: totalPassed,
        denied: totalDenied,
        challenged: totalChallenged,
        rateLimited: totalRateLimited,
        redirected: totalRedirected,
      }) satisfies Record<FirewallTrafficSeriesKey, number>,
    [
      totalPassed,
      totalDenied,
      totalChallenged,
      totalRateLimited,
      totalRedirected,
    ],
  )

  // Lowest total first (legend / tooltip preference). Recharts stacks
  // bottom-up, so render in this order for lowest at the bottom.
  const seriesByValueAsc = useMemo(
    () => sortFirewallTrafficSeriesByValueAsc(seriesTotals),
    [seriesTotals],
  )

  const [keepTrafficError, setKeepTrafficError] = useState(false)
  if (overview) {
    if (keepTrafficError) setKeepTrafficError(false)
  } else if (isError && !keepTrafficError) {
    setKeepTrafficError(true)
  }
  const hideMetricValues = !overview
  const showTrafficError = !overview && (isError || keepTrafficError)

  const metrics: StatCardProps[] = [
    {
      label: t('Passed'),
      value: totalPassed,
      change: passedChange,
      trend: changeTrend(passedChange),
    },
    {
      label: t('Denied'),
      value: totalDenied,
      change: deniedChange,
      trend: changeTrend(deniedChange),
    },
    {
      label: t('Challenged'),
      value: totalChallenged,
      change: challengedChange,
      trend: changeTrend(challengedChange),
      subStats: [
        {
          label: t('solved'),
          title: t('Challenge solves'),
          value: totalChallengeSolved,
          change: challengeSolvedChange,
          trend: changeTrend(challengeSolvedChange),
        },
        {
          label: '',
          title: t('Avg solve time'),
          value: avgSolveTimeMs,
          formatValue: formatFirewallSolveTime,
          change: avgSolveTimeChange,
          trend: changeTrend(avgSolveTimeChange),
        },
      ],
    },
    {
      label: t('Rate limited'),
      value: totalRateLimited,
      change: rateLimitedChange,
      trend: changeTrend(rateLimitedChange),
    },
    {
      label: t('Redirected'),
      value: totalRedirected,
      change: redirectedChange,
      trend: changeTrend(redirectedChange),
    },
    {
      label: t('Block rate'),
      value: blockRateValue,
      decimals: 1,
      suffix: '%',
      change: blockRateChange,
      trend: changeTrend(blockRateChange),
    },
  ]

  return (
    <div className="w-full">
      <div className="flex flex-col-reverse gap-3 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
            {hideMetricValues ? (
              <span className="text-[24px] font-semibold text-muted-foreground">
                -
              </span>
            ) : (
              <AnimatedCounter
                value={totalRequests}
                className={cn(
                  'text-[24px] font-semibold text-foreground',
                  USAGE_CHART_FADE_IN_CLASS_NAME,
                )}
              />
            )}
            <span className="text-[13px] text-muted-foreground">
              {t('requests')}
            </span>
            {hideMetricValues ? null : (
              <span
                className={cn(
                  'text-[12px] font-medium tabular-nums',
                  USAGE_CHART_FADE_IN_CLASS_NAME,
                  requestsChange > 0 &&
                    'text-emerald-600 dark:text-emerald-400',
                  requestsChange < 0 &&
                    'text-amber-600 dark:text-amber-400',
                  requestsChange === 0 && 'text-muted-foreground',
                )}
              >
                {requestsChange > 0 ? '+' : ''}
                {requestsChange}% {t('vs previous period')}
              </span>
            )}
          </div>
        </div>
        <div className="flex flex-wrap items-center justify-end gap-2 sm:gap-3">
          <UsageChartIntervalToggle
            value={resolvedChartInterval}
            onValueChange={setChartInterval}
            dateRange={dateRange}
            allowedIntervals={planChartIntervals}
            className="h-9"
          />
          <DateRangePicker
            dateRange={dateRange}
            onDateRangeChange={setDateRange}
            presetId={dateRangePresetId}
            className="h-9 shrink-0"
            retentionHours={
              hasFiniteUsageLogRetention(organizationPlan)
                ? usageLogRetentionHours
                : null
            }
          />
          <FirewallChartLiveControls
            isLive={liveUpdatesEnabled}
            onToggle={toggleLiveUpdatesEnabled}
          />
          <RefreshButton
            onClick={() => void handleRefresh()}
            isRefreshing={isRefreshing}
            disabled={liveUpdatesEnabled}
            tooltip={
              liveUpdatesEnabled
                ? t('Pause live updates to refresh manually')
                : undefined
            }
          />
        </div>
      </div>

      {showUsageHistoryLimitAlert &&
      hasFiniteUsageLogRetention(organizationPlan) ? (
        <UsageLogRetentionAlert
          retentionDays={usageLogRetentionDays}
          organizationId={project?.teamId}
          onAdjustRange={handleAdjustUsageDateRange}
        />
      ) : null}

      <div className="px-4 pb-4 pt-4 sm:px-6">
        <div className="mb-3 flex min-h-5 flex-wrap items-center justify-between gap-x-4 gap-y-2">
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
            {seriesByValueAsc.map((series) => (
              <div key={series.key} className="flex items-center gap-1.5">
                <span
                  className="h-2 w-2 rounded-full"
                  style={{ backgroundColor: series.color }}
                />
                <span className="text-[12px] text-muted-foreground">
                  {t(series.label)}
                </span>
              </div>
            ))}
          </div>
          <FirewallChartLiveIndicator
            isLive={liveUpdatesEnabled}
            isPolling={isLiveActive}
          />
        </div>

        <ChartArea>
          {showTrafficError ? (
            <FirewallUsageChartError
              error={error}
              retentionDays={usageLogRetentionDays}
              fallback={FIREWALL_TRAFFIC_FETCH_ERROR}
              onRetry={() => void refetch()}
            />
          ) : (
          <div
            key={overview ? 'data' : 'empty'}
            className={cn(
              surfaceClassName,
              'relative',
              overview && USAGE_CHART_FADE_IN_CLASS_NAME,
            )}
            aria-label={
              canSelect
                ? t('Drag on the chart to select a date range')
                : undefined
            }
          >
            <ResponsiveContainer
              {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}
              minHeight={OVERVIEW_CHART_HEIGHT}
            >
              <AreaChart
                data={chartData}
                margin={{ top: 8, right: 12, left: 0, bottom: 4 }}
                {...chartProps}
              >
                <defs>
                  {FIREWALL_TRAFFIC_SERIES.map((series) => (
                    <linearGradient
                      key={series.gradientId}
                      id={series.gradientId}
                      x1="0"
                      y1="0"
                      x2="0"
                      y2="1"
                    >
                      <stop
                        offset="0%"
                        stopColor={series.color}
                        stopOpacity={0.2}
                      />
                      <stop
                        offset="100%"
                        stopColor={series.color}
                        stopOpacity={0}
                      />
                    </linearGradient>
                  ))}
                </defs>
                <CartesianGrid
                  strokeDasharray="3 3"
                  stroke="hsl(var(--border))"
                  vertical={false}
                />
                <UsageChartXAxis
                  points={chartPoints}
                  dateRange={dateRange}
                  chartInterval={resolvedChartInterval}
                />
                <UsageChartYAxis
                  tickFormatter={yAxisTickFormatter}
                  domain={[
                    0,
                    (dataMax: number) => Math.ceil(dataMax * 1.08) || 1,
                  ]}
                />
                <Tooltip
                  isAnimationActive={false}
                  cursor={!isSelecting}
                  content={({ active, payload }) => {
                    if (isSelecting || !active || !payload?.length) return null
                    const point = payload[0]?.payload as {
                      fullDate?: string
                      requests?: number
                      denied?: number
                      challenged?: number
                      rateLimited?: number
                      redirected?: number
                    }

                    const seriesValue = (
                      entry: (typeof payload)[number],
                    ): number => {
                      const key = String(entry.dataKey ?? '')
                      if (
                        key === 'requests' ||
                        key === 'denied' ||
                        key === 'challenged' ||
                        key === 'rateLimited' ||
                        key === 'redirected'
                      ) {
                        return Number(point[key] ?? 0)
                      }
                      // Stacked areas may pass [y0, y1] as value.
                      if (Array.isArray(entry.value)) {
                        const [from, to] = entry.value as [number, number]
                        return Math.abs(Number(to) - Number(from)) || 0
                      }
                      return Number(entry.value ?? 0)
                    }

                    const sortedPayload = [...payload].sort(
                      (a, b) => seriesValue(a) - seriesValue(b),
                    )

                    return (
                      <div className="rounded-md border border-border bg-popover px-3 py-2">
                        <p className="mb-1.5 text-[11px] text-muted-foreground">
                          {point.fullDate}
                        </p>
                        <div className="space-y-1">
                          {sortedPayload.map((entry) => {
                            const seriesKey = String(
                              entry.dataKey ?? '',
                            ) as FirewallTrafficSeriesKey
                            const seriesColor =
                              FIREWALL_TRAFFIC_SERIES.find(
                                (series) => series.key === seriesKey,
                              )?.color ??
                              (typeof entry.color === 'string'
                                ? entry.color
                                : undefined)
                            return (
                              <div
                                key={String(entry.dataKey)}
                                className="flex items-center justify-between gap-6"
                              >
                                <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                  {seriesColor ? (
                                    <ChartSeriesDot color={seriesColor} />
                                  ) : null}
                                  {entry.name}
                                </span>
                                <span className="text-[13px] font-medium tabular-nums text-foreground">
                                  {seriesValue(entry).toLocaleString()}
                                </span>
                              </div>
                            )
                          })}
                        </div>
                      </div>
                    )
                  }}
                />
                {/* Recharts stacks bottom-up: render lowest totals first so the
                    highest-value series sits on top (and owns the outer stroke). */}
                {seriesByValueAsc.map((series) => (
                  <Area
                    key={series.key}
                    type="monotone"
                    stackId="firewall-traffic"
                    dataKey={series.key}
                    name={t(series.label)}
                    stroke={
                      seriesTotals[series.key] > 0
                        ? series.color
                        : 'transparent'
                    }
                    strokeWidth={2}
                    fill={`url(#${series.gradientId})`}
                    dot={false}
                    {...chartLiveAnimation}
                  />
                ))}
                <UsageChartBrushReferenceArea
                  left={brushLeft}
                  right={brushRight}
                />
              </AreaChart>
            </ResponsiveContainer>
          </div>
          )}
        </ChartArea>
      </div>

      <div className="grid grid-cols-2 border-y border-border sm:grid-cols-3 xl:grid-cols-6">
        {metrics.map((metric, index) => {
          const count = metrics.length
          const isLast = index === count - 1
          const lastRowStartMobile = count - (count % 2 || 2)
          const lastRowStartSm = count - (count % 3 || 3)
          const showBottomBorderMobile = index < lastRowStartMobile
          const showBottomBorderSm = index < lastRowStartSm
          const showEndBorderMobile = index % 2 === 0 && !isLast
          const showEndBorderSm = index % 3 !== 2 && !isLast
          return (
            <div
              key={metric.label}
              className={cn(
                'px-4 py-3 sm:px-6',
                showBottomBorderMobile && 'border-b border-border',
                !showBottomBorderSm && 'sm:border-b-0',
                'xl:border-b-0',
                showEndBorderMobile && 'border-e border-border sm:border-e-0',
                showEndBorderSm && 'sm:border-e sm:border-border xl:border-e-0',
                !isLast && 'xl:border-e xl:border-border',
              )}
            >
              <MetricTile
                label={metric.label}
                value={metric.value}
                change={metric.change}
                trend={metric.trend}
                subStats={metric.subStats}
                decimals={metric.decimals}
                suffix={metric.suffix}
                formatValue={metric.formatValue}
                unavailable={hideMetricValues}
              />
            </div>
          )
        })}
      </div>
    </div>
  )
}
