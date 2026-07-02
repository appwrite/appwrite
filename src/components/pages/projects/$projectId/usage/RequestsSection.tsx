import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { format } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import { AlertCircle } from 'lucide-react'
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
import { REQUESTS_BREAKDOWN_SECTIONS } from '@/lib/usage/requests-breakdowns'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import {
  useProjectRequestsBreakdowns,
  useProjectRequestsChartOnly,
  useCountries,
  refetchProjectRequestsUsageQueries,
  useUsageResourceBreakdownLookups,
} from '@/lib/react-query/hooks'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { buildCountryLookups } from '@/lib/locale/country-lookups'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  OVERVIEW_CHART_HEIGHT,
  OVERVIEW_REQUESTS_ERROR,
} from '../overview/chart-panel'
import {
  USAGE_CHART_MARGIN,
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
} from '@/lib/usage/chart-layout'
import { UsageChartXAxis, UsageChartYAxis } from '@/components/global/shared/ChartXAxis'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { useUsageFilters } from './usage-filters-context'
import { useT } from '@/lib/i18n/translate'
import { UsageBreakdownDrawer } from './_components/UsageBreakdownDrawer'
import {
  UsageBreakdownCard,
  UsageMetricCardFooter,
  UsageMetricCardShell,
} from './_components/UsageMetricCard'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
const API_REQUESTS_DESCRIPTION =
  'Total API requests during the selected period. Each call to your project endpoint counts as one request.'

const usageRequestsMetricHeaderClass = 'mt-2 min-h-[52px] flex flex-wrap items-baseline gap-x-2 gap-y-1'

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

function ChartSkeleton() {
  return (
    <Skeleton
      className="w-full shrink-0 rounded-md"
      style={{ height: OVERVIEW_CHART_HEIGHT }}
      aria-hidden
    />
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

function UsageRequestsChartError({ onRetry }: { onRetry?: () => void }) {
  const t = useT()
  return (
    <UsageRequestsChartArea>
      <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border bg-muted/20 px-6 text-center">
        <AlertCircle className="h-8 w-8 shrink-0 text-muted-foreground" />
        <div className="max-w-sm">
          <p className="text-[13px] font-medium text-foreground">
            {t(OVERVIEW_REQUESTS_ERROR.title)}
          </p>
          <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
            {t(OVERVIEW_REQUESTS_ERROR.message)}
          </p>
        </div>
        {onRetry ? (
          <Button variant="outline" size="sm" onClick={onRetry}>
            {t('Try again')}
          </Button>
        ) : null}
      </div>
    </UsageRequestsChartArea>
  )
}

type RequestsChartCardProps = {
  total: number
  changePercent: number
  chartPoints: { date: string; day: Date; total: number }[]
  isLoading: boolean
  isError: boolean
  onRetry?: () => void
}

function RequestsChartCard({
  total,
  changePercent,
  chartPoints,
  isLoading,
  isError,
  onRetry,
}: RequestsChartCardProps) {
  const t = useT()
  const { dateRange, chartInterval } = useUsageFilters()
  const chartData = useMemo(
    () =>
      chartPoints.map((point) => ({
        date: point.date,
        fullDate: format(point.day, 'MMM d, yyyy HH:mm'),
        value: point.total,
      })),
    [chartPoints],
  )

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
                      changePercent > 0 && 'text-emerald-600 dark:text-emerald-400',
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
          <UsageRequestsChartError onRetry={onRetry} />
        ) : isLoading ? (
          <ChartSkeleton />
        ) : chartData.length === 0 ? (
          <UsageRequestsChartArea>
            <div className="absolute inset-0 flex items-center justify-center text-[13px] text-muted-foreground">
              {t('No data for this date range')}
            </div>
          </UsageRequestsChartArea>
        ) : (
          <UsageRequestsChartArea>
            <ResponsiveContainer {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}>
              <AreaChart
                data={chartData}
                margin={USAGE_CHART_MARGIN}
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
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
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
              </AreaChart>
            </ResponsiveContainer>
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
  const [breakdownDrawer, setBreakdownDrawer] =
    useState<RequestsBreakdownDrawerState | null>(null)
  const showBreakdown = !disableUsageBreakdownQueries
  const { data: countriesData } = useCountries()
  const countryLookups = useMemo(
    () => buildCountryLookups(countriesData?.countries),
    [countriesData?.countries],
  )

  const {
    data: chartOverview,
    isLoading: isChartLoading,
    isPlaceholderData: isChartPlaceholderData,
    isError: isChartError,
    refetch: refetchChart,
  } = useProjectRequestsChartOnly(
    projectId,
    dateRange,
    true,
    chartInterval,
  )

  const breakdowns = useProjectRequestsBreakdowns(
    projectId,
    dateRange,
    showBreakdown,
  )

  const resourceBreakdownIds = useMemo(() => {
    const resourceSection = breakdowns.find(
      (entry) => entry.section.dimension === 'resourceId',
    )
    return resourceSection?.items.map((item) => item.label) ?? []
  }, [breakdowns])

  const { computeLookup, databaseLookup, storageLookup, tableLookup } =
    useUsageResourceBreakdownLookups(
      projectId,
      resourceBreakdownIds,
      showBreakdown && resourceBreakdownIds.length > 0,
    )

  useEffect(() => {
    registerRefreshHandler(
      () => refetchProjectRequestsUsageQueries(queryClient, projectId),
      'Usage data',
    )
    return () => unregisterRefreshHandler()
  }, [
    queryClient,
    projectId,
    registerRefreshHandler,
    unregisterRefreshHandler,
  ])

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
        onRetry={handleRetryChart}
      />

      {showBreakdown ? (
        <div className="grid items-stretch gap-6 lg:grid-cols-2">
          {breakdowns.map(({ section, items, isLoading, isError }) => (
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
                projectId={projectId}
                computeLookup={
                  section.dimension === 'resourceId' ? computeLookup : undefined
                }
                databaseLookup={
                  section.dimension === 'resourceId' ? databaseLookup : undefined
                }
                storageLookup={
                  section.dimension === 'resourceId' ? storageLookup : undefined
                }
                tableLookup={
                  section.dimension === 'resourceId' ? tableLookup : undefined
                }
                isLoading={isLoading}
                isError={isError}
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
          ))}
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
