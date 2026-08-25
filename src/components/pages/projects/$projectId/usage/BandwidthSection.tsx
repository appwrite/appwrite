'use client'

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
import { createCompactBytesAxisTickFormatter } from '@/lib/usage/format-metric'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import {
  OVERVIEW_BANDWIDTH_ERROR,
  OVERVIEW_CHART_HEIGHT,
} from '../overview/chart-panel'
import {
  USAGE_CHART_MARGIN,
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
} from '@/lib/usage/chart-layout'
import {
  UsageChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { useUsageFilters } from './usage-filters-context'
import { useUsageChartBrushSelect } from '@/hooks/use-usage-chart-brush'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import {
  formatBandwidthTotal,
  formatBandwidthValue,
  resolveBandwidthDualChartDisplay,
  resolveBandwidthStackedYAxisDomain,
  type BandwidthDualChartPoint,
} from '@/lib/usage/bandwidth-events'
import { shouldShowUsageChartSkeleton } from '@/lib/usage/usage-chart-loading'
import {
  sumUsageChartPoints,
  type UsageEventBreakdownDimension,
} from '@/lib/usage/usage-events-common'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import {
  useProjectBandwidthBreakdowns,
  useProjectBandwidthChartOnly,
  useCountryLookups,
  refetchProjectBandwidthUsageQueries,
  useUsageResourceBreakdownLookups,
} from '@/lib/react-query/hooks'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { Skeleton } from '@/components/ui/skeleton'
import { splitUsageBreakdownEntries } from '@/lib/usage/usage-resources-breakdown'
import { UsageResourceBreakdownCard } from './_components/UsageResourceBreakdownCard'
import { UsageBreakdownDrawer } from './_components/UsageBreakdownDrawer'
import {
  UsageBreakdownCard,
  UsageMetricCardFooter,
  UsageMetricCardShell,
} from './_components/UsageMetricCard'
import { UsageSectionChartError } from './_components/UsageSectionChartError'
import { UsageChartBrushReferenceArea } from './_components/UsageChartBrushReferenceArea'

const BANDWIDTH_DESCRIPTION =
  'Total inbound and outbound network traffic during the selected period. Includes API responses, file transfers, and function I/O.'

const usageBandwidthMetricHeaderClass =
  'mt-2 min-h-[52px] flex flex-wrap items-baseline gap-x-2 gap-y-1'

type BandwidthSectionProps = {
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

function UsageBandwidthChartArea({ children }: { children: ReactNode }) {
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

function UsageBandwidthChartError({
  error,
  onRetry,
}: {
  error?: unknown
  onRetry?: () => void
}) {
  return (
    <UsageBandwidthChartArea>
      <UsageSectionChartError
        error={error}
        errorTitle={OVERVIEW_BANDWIDTH_ERROR.title}
        errorMessage={OVERVIEW_BANDWIDTH_ERROR.message}
        onRetry={onRetry}
      />
    </UsageBandwidthChartArea>
  )
}

type BandwidthChartCardProps = {
  total: number
  changePercent: number
  dualChartPoints: BandwidthDualChartPoint[]
  isLoading: boolean
  isError: boolean
  chartError?: unknown
  onRetry?: () => void
}

function BandwidthChartCard({
  total,
  changePercent,
  dualChartPoints,
  isLoading,
  isError,
  chartError,
  onRetry,
}: BandwidthChartCardProps) {
  const t = useT()
  const { dateRange, chartInterval, onDateRangeChange } = useUsageFilters()
  const chartData = useMemo(
    () =>
      dualChartPoints.map((point) => ({
        date: point.date,
        fullDate: formatLocalizedDate(point.day, 'MMM d, yyyy HH:mm'),
        inbound: point.inbound,
        outbound: point.outbound,
        total: point.total,
      })),
    [dualChartPoints],
  )
  const {
    canSelect,
    isSelecting,
    brushLeft,
    brushRight,
    surfaceClassName,
    chartProps,
  } = useUsageChartBrushSelect({
    points: dualChartPoints,
    chartInterval,
    onDateRangeChange,
  })

  const formattedTotal = formatBandwidthTotal(total)
  const changeLabel =
    changePercent > 0
      ? `+${changePercent}%`
      : changePercent < 0
        ? `${changePercent}%`
        : '0%'
  const { showDualSeries, axisMax: bandwidthAxisMax } = useMemo(
    () => resolveBandwidthDualChartDisplay(chartData),
    [chartData],
  )
  const yAxisTickFormatter = useMemo(
    () => createCompactBytesAxisTickFormatter(bandwidthAxisMax),
    [bandwidthAxisMax],
  )
  const bandwidthYAxisDomain = useMemo(
    () =>
      showDualSeries
        ? resolveBandwidthStackedYAxisDomain(bandwidthAxisMax)
        : undefined,
    [showDualSeries, bandwidthAxisMax],
  )

  return (
    <UsageMetricCardShell>
      <div className="shrink-0 flex flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 flex-1">
          <h3 className="text-[14px] font-medium text-foreground">
            {t('Bandwidth over time')}
          </h3>

          <div className={usageBandwidthMetricHeaderClass}>
            {isLoading ? (
              <ChartMetricHeaderSkeleton />
            ) : (
              <>
                <span className="text-[24px] font-semibold tabular-nums text-foreground">
                  {formattedTotal}
                </span>
                {!isError && dualChartPoints.length > 0 ? (
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

        {showDualSeries && !isLoading ? (
          <div className="flex shrink-0 flex-wrap items-center gap-3">
            <div className="flex items-center gap-1.5">
              <div
                className="h-2 w-2 rounded-full"
                style={{ backgroundColor: 'var(--chart-2)' }}
              />
              <span className="text-[11px] text-muted-foreground">
                {t('Inbound')}
              </span>
            </div>
            <div className="flex items-center gap-1.5">
              <div
                className="h-2 w-2 rounded-full"
                style={{ backgroundColor: 'var(--chart-brand)' }}
              />
              <span className="text-[11px] text-muted-foreground">
                {t('Outbound')}
              </span>
            </div>
          </div>
        ) : null}
      </div>

      <div className="flex flex-1 flex-col p-4">
        {isError ? (
          <UsageBandwidthChartError error={chartError} onRetry={onRetry} />
        ) : isLoading ? (
          <ChartSkeleton label={t('Loading usage data')} />
        ) : chartData.length === 0 ? (
          <UsageBandwidthChartArea>
            <div className="absolute inset-0 flex items-center justify-center text-[13px] text-muted-foreground">
              {t('No data for this date range')}
            </div>
          </UsageBandwidthChartArea>
        ) : (
          <UsageBandwidthChartArea>
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
                      id="usage-bandwidth-inbound-gradient"
                      x1="0"
                      y1="0"
                      x2="0"
                      y2="1"
                    >
                      <stop
                        offset="0%"
                        stopColor="var(--chart-2)"
                        stopOpacity={0.15}
                      />
                      <stop
                        offset="100%"
                        stopColor="var(--chart-2)"
                        stopOpacity={0}
                      />
                    </linearGradient>
                    <linearGradient
                      id="usage-bandwidth-outbound-gradient"
                      x1="0"
                      y1="0"
                      x2="0"
                      y2="1"
                    >
                      <stop
                        offset="0%"
                        stopColor="var(--chart-brand)"
                        stopOpacity={0.2}
                      />
                      <stop
                        offset="100%"
                        stopColor="var(--chart-brand)"
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
                    points={dualChartPoints}
                    dateRange={dateRange}
                    chartInterval={chartInterval}
                  />
                  <UsageChartYAxis
                    tickFormatter={yAxisTickFormatter}
                    domain={bandwidthYAxisDomain}
                  />
                  <Tooltip
                    cursor={!isSelecting}
                    content={({ active, payload }) => {
                      if (isSelecting || !active || !payload?.length)
                        return null
                      const data = payload[0].payload as {
                        fullDate: string
                        inbound: number
                        outbound: number
                      }
                      return (
                        <div className="rounded-md border border-border bg-popover px-3 py-2">
                          <p className="mb-1 text-[11px] text-muted-foreground">
                            {data.fullDate}
                          </p>
                          {showDualSeries ? (
                            <div className="space-y-0.5">
                              <p className="flex items-center gap-1.5 text-[13px] font-medium text-foreground">
                                <ChartSeriesDot color="var(--chart-2)" />
                                {formatBandwidthValue(data.inbound)}{' '}
                                <span className="font-normal text-muted-foreground">
                                  {t('inbound')}
                                </span>
                              </p>
                              <p className="flex items-center gap-1.5 text-[13px] font-medium text-foreground">
                                <ChartSeriesDot color="var(--chart-brand)" />
                                {formatBandwidthValue(data.outbound)}{' '}
                                <span className="font-normal text-muted-foreground">
                                  {t('outbound')}
                                </span>
                              </p>
                              <p className="border-t border-border pt-1 text-[13px] font-medium text-foreground">
                                {formatBandwidthValue(
                                  data.inbound + data.outbound,
                                )}{' '}
                                <span className="font-normal text-muted-foreground">
                                  {t('total')}
                                </span>
                              </p>
                            </div>
                          ) : (
                            <p className="text-[13px] font-medium text-foreground">
                              {formatBandwidthValue(
                                data.inbound + data.outbound,
                              )}
                            </p>
                          )}
                        </div>
                      )
                    }}
                  />
                  {showDualSeries ? (
                    <>
                      <Area
                        type="monotone"
                        dataKey="inbound"
                        name="Inbound"
                        stackId="bandwidth"
                        stroke="var(--chart-2)"
                        strokeWidth={2}
                        fill="url(#usage-bandwidth-inbound-gradient)"
                        {...CHART_ANIMATION_DISABLED}
                      />
                      <Area
                        type="monotone"
                        dataKey="outbound"
                        name="Outbound"
                        stackId="bandwidth"
                        stroke="var(--chart-brand)"
                        strokeWidth={2}
                        fill="url(#usage-bandwidth-outbound-gradient)"
                        {...CHART_ANIMATION_DISABLED}
                      />
                    </>
                  ) : (
                    <Area
                      type="monotone"
                      dataKey="total"
                      stroke="var(--chart-brand)"
                      strokeWidth={2}
                      fill="url(#usage-bandwidth-outbound-gradient)"
                      name="Bandwidth"
                      {...CHART_ANIMATION_DISABLED}
                    />
                  )}
                  <UsageChartBrushReferenceArea
                    left={brushLeft}
                    right={brushRight}
                  />
                </AreaChart>
              </ResponsiveContainer>
            </div>
          </UsageBandwidthChartArea>
        )}
      </div>

      <UsageMetricCardFooter description={BANDWIDTH_DESCRIPTION} />
    </UsageMetricCardShell>
  )
}

type BandwidthBreakdownDrawerState = {
  title: string
  description: string
  dimension: UsageEventBreakdownDimension
  labelVariant: 'mono' | 'default'
}

export function BandwidthSection({
  projectId,
  dateRange,
  chartInterval,
}: BandwidthSectionProps) {
  const queryClient = useQueryClient()
  const { registerRefreshHandler, unregisterRefreshHandler } = useRefresh()
  const { disableUsageBreakdownQueries } = useDebugOverrides()
  const { isSelfHosted } = useConsoleProfile()
  const [breakdownDrawer, setBreakdownDrawer] =
    useState<BandwidthBreakdownDrawerState | null>(null)
  const showBreakdown = !disableUsageBreakdownQueries
  const { lookups: countryLookups } = useCountryLookups()

  const {
    data: chartOverview,
    isLoading: isChartLoading,
    isPlaceholderData: isChartPlaceholderData,
    isError: isChartError,
    error: chartError,
    refetch: refetchChart,
  } = useProjectBandwidthChartOnly(projectId, dateRange, true, chartInterval)

  const breakdowns = useProjectBandwidthBreakdowns(
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
      () => refetchProjectBandwidthUsageQueries(queryClient, projectId),
      'Usage data',
    )
    return () => unregisterRefreshHandler()
  }, [queryClient, projectId, registerRefreshHandler, unregisterRefreshHandler])

  const dualChartPoints = isChartError
    ? []
    : (chartOverview?.dualChartPoints ?? [])
  const showChartLoading = shouldShowUsageChartSkeleton(
    isChartError,
    isChartLoading,
    isChartPlaceholderData,
  )
  const total = sumUsageChartPoints(chartOverview?.chartPoints ?? [])
  const changePercent = chartOverview?.changePercent ?? 0

  const handleRetryChart = () => void refetchChart()
  const handleRetryAll = () => {
    void refetchChart()
  }

  return (
    <div className="space-y-6">
      <BandwidthChartCard
        total={total}
        changePercent={changePercent}
        dualChartPoints={dualChartPoints}
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
                  errorTitle={OVERVIEW_BANDWIDTH_ERROR.title}
                  errorMessage={OVERVIEW_BANDWIDTH_ERROR.message}
                  formatValue={formatBandwidthValue}
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
                errorTitle={OVERVIEW_BANDWIDTH_ERROR.title}
                errorMessage={OVERVIEW_BANDWIDTH_ERROR.message}
                formatValue={formatBandwidthValue}
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
                errorTitle={OVERVIEW_BANDWIDTH_ERROR.title}
                errorMessage={OVERVIEW_BANDWIDTH_ERROR.message}
                formatValue={formatBandwidthValue}
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
          kind="bandwidth"
        />
      ) : null}
    </div>
  )
}
