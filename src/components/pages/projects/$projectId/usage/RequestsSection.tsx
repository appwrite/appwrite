import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import type { DateRange } from 'react-day-picker'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import { cn } from '@/lib/utils'
import {
  createCompactCountAxisTickFormatter,
  getChartSeriesMax,
} from '@/lib/usage/format-metric'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import {
  formatRequestsTotal,
  formatRequestsValue,
} from '@/lib/usage/requests-events'
import { shouldShowUsageChartSkeleton } from '@/lib/usage/usage-chart-loading'
import { sumUsageChartPoints } from '@/lib/usage/usage-events-common'
import type { UsageEventBreakdownDimension } from '@/lib/usage/usage-events-common'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import {
  useProjectRequestsBreakdowns,
  useProjectRequestsChartOnly,
  useCountryLookups,
  refetchProjectRequestsUsageQueries,
  useUsageResourceBreakdownLookups,
} from '@/lib/react-query/hooks'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  OVERVIEW_CHART_HEIGHT,
  OVERVIEW_REQUESTS_ERROR,
} from '../overview/chart-panel'
import {
  USAGE_CHART_MARGIN,
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
} from '@/lib/usage/chart-layout'
import {
  UsageChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { useUsageFilters } from './usage-filters-context'
import { useUsageChartBrushSelect } from '@/hooks/use-usage-chart-brush'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { splitUsageBreakdownEntries } from '@/lib/usage/usage-resources-breakdown'
import { UsageBreakdownDrawer } from './_components/UsageBreakdownDrawer'
import { UsageResourceBreakdownCard } from './_components/UsageResourceBreakdownCard'
import {
  UsageBreakdownCard,
  UsageMetricCardFooter,
  UsageMetricCardShell,
} from './_components/UsageMetricCard'
import { UsageSectionChartError } from './_components/UsageSectionChartError'
import { UsageChartBrushReferenceArea } from './_components/UsageChartBrushReferenceArea'
import { Skeleton } from '@/components/ui/skeleton'
const API_REQUESTS_DESCRIPTION =
  'Total API requests during the selected period. Each call to your project endpoint counts as one request.'

const usageRequestsMetricHeaderClass =
  'mt-2 min-h-[52px] flex flex-wrap items-baseline gap-x-2 gap-y-1'

type RequestsSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

function ChartMetricHeaderSkeleton() {
  return (
    <>
      <Skeleton className="h-7 w-28 shrink-0 rounded-sm" />
      <Skeleton className="h-4 w-[4.5rem] shrink-0 rounded-sm" />
      <Skeleton className="h-3 w-44 max-w-full shrink-0 rounded-sm" />
    </>
  )
}

function ChartSkeleton({ label }: { label: string }) {
  return (
    <div
      role="status"
      aria-label={label}
      className="relative w-full shrink-0"
      style={{ height: OVERVIEW_CHART_HEIGHT }}
    >
      <Skeleton className="absolute inset-0 rounded-md" />
      <span className="sr-only">{label}</span>
    </div>
  )
}

function UsageRequestsChartArea({ children }: { children: ReactNode }) {
  return (
    <div
      className={cn(
        'relative w-full shrink-0 text-muted-foreground',
        FORCE_LTR_CLASS,
      )}
      style={{ height: OVERVIEW_CHART_HEIGHT }}
    >
      {children}
    </div>
  )
}

function UsageRequestsChartError({
  error,
  onRetry,
}: {
  error?: unknown
  onRetry?: () => void
}) {
  return (
    <UsageRequestsChartArea>
      <UsageSectionChartError
        error={error}
        errorTitle={OVERVIEW_REQUESTS_ERROR.title}
        errorMessage={OVERVIEW_REQUESTS_ERROR.message}
        onRetry={onRetry}
      />
    </UsageRequestsChartArea>
  )
}

type RequestsChartCardProps = {
  total: number
  changePercent: number
  chartPoints: { date: string; day: Date; total: number }[]
  isLoading: boolean
  isError: boolean
  chartError?: unknown
  onRetry?: () => void
}

function RequestsChartCard({
  total,
  changePercent,
  chartPoints,
  isLoading,
  isError,
  chartError,
  onRetry,
}: RequestsChartCardProps) {
  const t = useT()
  const { dateRange, chartInterval, onDateRangeChange } = useUsageFilters()
  const chartData = useMemo(
    () =>
      chartPoints.map((point) => ({
        date: point.date,
        fullDate: formatLocalizedDate(point.day, 'MMM d, yyyy HH:mm'),
        value: point.total,
      })),
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
    points: chartPoints,
    chartInterval,
    onDateRangeChange,
  })

  const chartColor = 'var(--chart-brand)'
  const formattedTotal = formatRequestsTotal(total)
  const changeLabel =
    changePercent > 0
      ? `+${changePercent}%`
      : changePercent < 0
        ? `${changePercent}%`
        : '0%'
  const chartAxisMax = useMemo(() => getChartSeriesMax(chartData), [chartData])
  const yAxisTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(chartAxisMax),
    [chartAxisMax],
  )

  return (
    <UsageMetricCardShell>
      <div className="shrink-0 flex flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 flex-1">
          <h3 className="text-[14px] font-medium text-foreground">
            {t('Requests over time')}
          </h3>

          <div className={usageRequestsMetricHeaderClass}>
            {isLoading ? (
              <ChartMetricHeaderSkeleton />
            ) : (
              <>
                <span className="text-[24px] font-semibold tabular-nums text-foreground">
                  {formattedTotal}
                </span>
                <span className="text-[13px] text-muted-foreground">
                  {t('requests')}
                </span>
                {!isError && chartPoints.length > 0 ? (
                  <span
                    className={cn(
                      'text-[12px] font-medium tabular-nums',
                      changePercent > 0 &&
                        'text-emerald-600 dark:text-emerald-400',
                      changePercent < 0 && 'text-amber-600 dark:text-amber-400',
                      changePercent === 0 && 'text-muted-foreground',
                    )}
                  >
                    {changeLabel} {t('vs previous period')}
                  </span>
                ) : !isLoading ? (
                  <span
                    className="invisible text-[12px] font-medium tabular-nums"
                    aria-hidden
                  >
                    0% {t('vs previous period')}
                  </span>
                ) : null}
              </>
            )}
          </div>
        </div>
      </div>

      <div className="flex flex-1 flex-col p-4">
        {isError ? (
          <UsageRequestsChartError error={chartError} onRetry={onRetry} />
        ) : isLoading ? (
          <ChartSkeleton label={t('Loading usage data')} />
        ) : chartData.length === 0 ? (
          <UsageRequestsChartArea>
            <div className="absolute inset-0 flex items-center justify-center text-[13px] text-muted-foreground">
              {t('No data for this date range')}
            </div>
          </UsageRequestsChartArea>
        ) : (
          <UsageRequestsChartArea>
            <div
              className={surfaceClassName}
              aria-label={
                canSelect
                  ? t('Drag on the chart to select a date range')
                  : undefined
              }
            >
              <ResponsiveContainer {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}>
                <AreaChart
                  data={chartData}
                  margin={USAGE_CHART_MARGIN}
                  {...chartProps}
                >
                  <defs>
                    <linearGradient
                      id="usage-requests-gradient"
                      x1="0"
                      y1="0"
                      x2="0"
                      y2="1"
                    >
                      <stop
                        offset="0%"
                        stopColor={chartColor}
                        stopOpacity={0.2}
                      />
                      <stop
                        offset="100%"
                        stopColor={chartColor}
                        stopOpacity={0}
                      />
                    </linearGradient>
                  </defs>
                  <CartesianGrid
                    strokeDasharray="3 3"
                    stroke="hsl(var(--border))"
                    vertical={false}
                  />
                  <UsageChartXAxis
                    points={chartPoints}
                    dateRange={dateRange}
                    chartInterval={chartInterval}
                  />
                  <UsageChartYAxis tickFormatter={yAxisTickFormatter} />
                  <Tooltip
                    cursor={!isSelecting}
                    content={({ active, payload }) => {
                      if (isSelecting || !active || !payload?.length)
                        return null
                      const data = payload[0].payload as {
                        fullDate: string
                        value: number
                      }
                      return (
                        <div className="rounded-md border border-border bg-popover px-3 py-2">
                          <p className="mb-1 text-[11px] text-muted-foreground">
                            {data.fullDate}
                          </p>
                          <p className="text-[13px] font-medium text-foreground">
                            {formatRequestsValue(data.value)}{' '}
                            <span className="font-normal text-muted-foreground">
                              {t('requests')}
                            </span>
                          </p>
                        </div>
                      )
                    }}
                  />
                  <Area
                    type="monotone"
                    dataKey="value"
                    stroke={chartColor}
                    strokeWidth={2}
                    fill="url(#usage-requests-gradient)"
                    name="Requests over time"
                    {...CHART_ANIMATION_DISABLED}
                  />
                  <UsageChartBrushReferenceArea
                    left={brushLeft}
                    right={brushRight}
                  />
                </AreaChart>
              </ResponsiveContainer>
            </div>
          </UsageRequestsChartArea>
        )}
      </div>

      <UsageMetricCardFooter description={API_REQUESTS_DESCRIPTION} />
    </UsageMetricCardShell>
  )
}

type RequestsBreakdownDrawerState = {
  title: string
  description: string
  dimension: UsageEventBreakdownDimension
  labelVariant: 'mono' | 'default'
}

export function RequestsSection({
  projectId,
  dateRange,
  chartInterval,
}: RequestsSectionProps) {
  const queryClient = useQueryClient()
  const { registerRefreshHandler, unregisterRefreshHandler } = useRefresh()
  const { disableUsageBreakdownQueries } = useDebugOverrides()
  const { isSelfHosted } = useConsoleProfile()
  const [breakdownDrawer, setBreakdownDrawer] =
    useState<RequestsBreakdownDrawerState | null>(null)
  const showBreakdown = !disableUsageBreakdownQueries
  const { lookups: countryLookups } = useCountryLookups()

  const {
    data: chartOverview,
    isLoading: isChartLoading,
    isPlaceholderData: isChartPlaceholderData,
    isError: isChartError,
    error: chartError,
    refetch: refetchChart,
  } = useProjectRequestsChartOnly(projectId, dateRange, true, chartInterval)

  const breakdowns = useProjectRequestsBreakdowns(
    projectId,
    dateRange,
    showBreakdown,
    !isSelfHosted,
  )

  const { standardEntries, resourceEntry, resourceTypeEntry } = useMemo(
    () => splitUsageBreakdownEntries(breakdowns),
    [breakdowns],
  )

  const resourceBreakdownItems = useMemo(
    () => resourceEntry?.items ?? [],
    [resourceEntry?.items],
  )

  const { computeLookup, databaseLookup, storageLookup, tableLookup } =
    useUsageResourceBreakdownLookups(
      projectId,
      resourceBreakdownItems,
      showBreakdown && resourceBreakdownItems.length > 0,
    )

  useEffect(() => {
    registerRefreshHandler(
      () => refetchProjectRequestsUsageQueries(queryClient, projectId),
      'Usage data',
    )
    return () => unregisterRefreshHandler()
  }, [queryClient, projectId, registerRefreshHandler, unregisterRefreshHandler])

  const chartPoints = isChartError ? [] : (chartOverview?.chartPoints ?? [])
  const showChartLoading = shouldShowUsageChartSkeleton(
    isChartError,
    isChartLoading,
    isChartPlaceholderData,
  )
  const total = sumUsageChartPoints(chartPoints)
  const changePercent = chartOverview?.changePercent ?? 0

  const handleRetryChart = () => void refetchChart()
  const handleRetryAll = () => {
    void refetchChart()
  }

  return (
    <div className="space-y-6">
      <RequestsChartCard
        total={total}
        changePercent={changePercent}
        chartPoints={chartPoints}
        isLoading={showChartLoading}
        isError={isChartError}
        chartError={chartError}
        onRetry={handleRetryChart}
      />

      {showBreakdown ? (
        <div className="grid items-stretch gap-6 lg:grid-cols-2">
          {standardEntries.map(
            ({ section, items, isLoading, isError, error }) => (
              <div
                key={section.dimension}
                className="flex h-full min-h-0 flex-col"
              >
                <UsageBreakdownCard
                  title={section.title}
                  description={section.description}
                  dimension={section.dimension}
                  items={items}
                  labelVariant={section.labelVariant}
                  countryLookups={countryLookups}
                  isLoading={isLoading}
                  isError={isError}
                  error={error}
                  errorTitle={OVERVIEW_REQUESTS_ERROR.title}
                  errorMessage={OVERVIEW_REQUESTS_ERROR.message}
                  formatValue={formatRequestsValue}
                  onRetry={handleRetryAll}
                  onShowMore={() =>
                    setBreakdownDrawer({
                      title: section.title,
                      description: section.description,
                      dimension: section.dimension,
                      labelVariant: section.labelVariant,
                    })
                  }
                />
              </div>
            ),
          )}

          {resourceEntry ? (
            <div className="flex h-full min-h-0 flex-col">
              <UsageResourceBreakdownCard
                description={resourceEntry.section.description}
                items={resourceEntry.items}
                isLoading={resourceEntry.isLoading}
                isError={resourceEntry.isError}
                error={resourceEntry.error}
                countryLookups={countryLookups}
                computeLookup={computeLookup}
                databaseLookup={databaseLookup}
                storageLookup={storageLookup}
                tableLookup={tableLookup}
                errorTitle={OVERVIEW_REQUESTS_ERROR.title}
                errorMessage={OVERVIEW_REQUESTS_ERROR.message}
                formatValue={formatRequestsValue}
                onRetry={handleRetryAll}
                onShowMore={() =>
                  setBreakdownDrawer({
                    title: 'Resources',
                    description: resourceEntry.section.description,
                    dimension: 'resource',
                    labelVariant: 'default',
                  })
                }
              />
            </div>
          ) : null}

          {resourceTypeEntry ? (
            <div className="flex h-full min-h-0 flex-col">
              <UsageBreakdownCard
                title={resourceTypeEntry.section.title}
                description={resourceTypeEntry.section.description}
                dimension={resourceTypeEntry.section.dimension}
                items={resourceTypeEntry.items}
                labelVariant={resourceTypeEntry.section.labelVariant}
                countryLookups={countryLookups}
                isLoading={resourceTypeEntry.isLoading}
                isError={resourceTypeEntry.isError}
                error={resourceTypeEntry.error}
                errorTitle={OVERVIEW_REQUESTS_ERROR.title}
                errorMessage={OVERVIEW_REQUESTS_ERROR.message}
                formatValue={formatRequestsValue}
                onRetry={handleRetryAll}
                onShowMore={() =>
                  setBreakdownDrawer({
                    title: resourceTypeEntry.section.title,
                    description: resourceTypeEntry.section.description,
                    dimension: resourceTypeEntry.section.dimension,
                    labelVariant: resourceTypeEntry.section.labelVariant,
                  })
                }
              />
            </div>
          ) : null}
        </div>
      ) : null}

      {breakdownDrawer ? (
        <UsageBreakdownDrawer
          open={breakdownDrawer !== null}
          onOpenChange={(open) => {
            if (!open) setBreakdownDrawer(null)
          }}
          projectId={projectId}
          dateRange={dateRange}
          title={breakdownDrawer.title}
          description={breakdownDrawer.description}
          dimension={breakdownDrawer.dimension}
          labelVariant={breakdownDrawer.labelVariant}
        />
      ) : null}
    </div>
  )
}
