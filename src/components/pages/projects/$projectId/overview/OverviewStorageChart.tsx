'use client'

import { memo, useMemo, type ReactNode } from 'react'
import { Area, AreaChart, ResponsiveContainer, Tooltip } from 'recharts'
import { useParams } from '@tanstack/react-router'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import type { OverviewStorageChartPoint } from '@/lib/usage/storage-usage'
import { OVERVIEW_STORAGE_CHART_TITLE } from '@/lib/usage/storage-usage'
import { OverviewViewAllUsageLink } from './OverviewViewAllUsageLink'
import {
  OVERVIEW_CHART_HEIGHT,
  OVERVIEW_STORAGE_ERROR,
  USAGE_CHART_MARGIN,
  overviewChartPanelBodyClass,
  overviewChartPanelChartAreaClass,
  overviewChartPanelChartFillClass,
  overviewChartPanelEmptyClass,
  overviewChartPanelHeaderActionsClass,
  overviewChartPanelHeaderClass,
} from './chart-panel'
import { OverviewChartPanelError } from './OverviewChartPanelError'
import { MetricValueWithUnit } from './MetricValueWithUnit'
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import {
  createUsageChartAxisTickFormatter,
  formatCompactBytes,
} from '@/lib/usage/format-metric'
import {
  fillChartPointsGaps,
} from '@/lib/usage/usage-events-common'
import { resolveUsageDateBounds } from '@/lib/usage/usage-date-range'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  resolveUsageChartIntervalForRange,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import { UsageChartXAxis, UsageChartYAxis } from '@/components/global/shared/ChartXAxis'
import { USAGE_CHART_RESPONSIVE_CONTAINER_PROPS } from '@/lib/usage/chart-layout'
import type { DateRange } from 'react-day-picker'

/** One stacked series per `resourceType` family of the unified storage gauge. */
const STORAGE_SERIES = [
  {
    key: 'buckets' as const,
    label: 'Buckets',
    color: 'var(--chart-brand)',
    gradientId: 'overview-storage-buckets-gradient',
  },
  {
    key: 'databases' as const,
    label: 'Databases',
    color: 'var(--chart-2)',
    gradientId: 'overview-storage-databases-gradient',
  },
  {
    key: 'functions' as const,
    label: 'Functions',
    color: 'var(--chart-3)',
    gradientId: 'overview-storage-functions-gradient',
  },
  {
    key: 'sites' as const,
    label: 'Sites',
    color: 'var(--chart-4)',
    gradientId: 'overview-storage-sites-gradient',
  },
]

const SKELETON_CHART_STROKE = 'hsl(var(--muted-foreground) / 0.4)'
const SKELETON_CHART_FILL = 'hsl(var(--muted-foreground))'
const SKELETON_WAVE = [
  0.42, 0.58, 0.51, 0.68, 0.59, 0.72, 0.64, 0.7, 0.55, 0.74, 0.62, 0.69,
] as const

type OverviewStorageChartProps = {
  className?: string
  title?: string
  dateRange?: DateRange
  chartInterval?: UsageChartInterval
  chartData?: OverviewStorageChartPoint[]
  isLoading?: boolean
  isError?: boolean
  onRetry?: () => void
  /** When set, error state shows Upgrade plan instead of retry. */
  upgradeOrgId?: string | null
  errorTitle?: string
  errorMessage?: ReactNode
  isPanelVisible?: boolean
}

function buildSkeletonChartData(
  dateRange: DateRange | undefined,
  chartInterval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): OverviewStorageChartPoint[] {
  const { from, to } = resolveUsageDateBounds(dateRange)
  const resolvedInterval = resolveUsageChartIntervalForRange(chartInterval, dateRange)
  const shell = fillChartPointsGaps(new Map(), from, to, resolvedInterval)
  const peak = 2_000_000_000

  return shell.map((point, index) => {
    const wave = SKELETON_WAVE[index % SKELETON_WAVE.length]
    const buckets = Math.round(peak * 0.56 * wave)
    const databases = Math.round(peak * 0.22 * wave)
    const functions = Math.round(peak * 0.14 * wave)
    const sites = Math.round(peak * 0.08 * wave)

    return {
      date: point.date,
      day: point.day,
      buckets,
      databases,
      functions,
      sites,
      total: buckets + databases + functions + sites,
    }
  })
}

interface StorageTooltipProps {
  active?: boolean
  payload?: Array<{
    value: number
    dataKey: string
    payload: OverviewStorageChartPoint
  }>
}

function StorageTooltip({ active, payload }: StorageTooltipProps) {
  const t = useT()
  if (!active || !payload?.length) return null

  const data = payload[0]?.payload
  if (!data) return null

  const format = (value: number) => formatCompactBytes(value, { compact: true })

  return (
    <div className="rounded-lg border border-border bg-popover px-3 py-2.5">
      <p className="mb-2 text-[12px] font-medium text-foreground">{data.date}</p>
      <div className="space-y-1.5">
        {STORAGE_SERIES.map((series) => (
          <div
            key={series.key}
            className="flex items-center justify-between gap-6"
          >
            <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
              <ChartSeriesDot color={series.color} />
              {t(series.label)}
            </span>
            <span className="text-[12px] font-medium text-foreground">
              <MetricValueWithUnit
                value={format(data[series.key])}
                className="text-[12px] font-medium text-foreground"
                unitClassName="text-muted-foreground"
              />
            </span>
          </div>
        ))}
        <div className="flex items-center justify-between gap-6 border-t border-border pt-1.5">
          <span className="text-[11px] text-muted-foreground">{t('Total')}</span>
          <span className="text-[12px] font-medium text-foreground">
            <MetricValueWithUnit
              value={format(data.total)}
              className="text-[12px] font-medium text-foreground"
              unitClassName="text-muted-foreground"
            />
          </span>
        </div>
      </div>
    </div>
  )
}

export const OverviewStorageChart = memo(function OverviewStorageChart({
  className,
  title = OVERVIEW_STORAGE_CHART_TITLE,
  dateRange,
  chartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  chartData = [],
  isLoading = false,
  isError = false,
  onRetry,
  upgradeOrgId,
  errorTitle = OVERVIEW_STORAGE_ERROR.title,
  errorMessage = OVERVIEW_STORAGE_ERROR.message,
  isPanelVisible = true,
}: OverviewStorageChartProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })

  const skeletonChartData = useMemo(
    () => buildSkeletonChartData(dateRange, chartInterval),
    [dateRange, chartInterval],
  )

  const showChartSkeleton = isLoading
  const showEmptyState = !isLoading && chartData.length === 0
  const activeChartData = showChartSkeleton ? skeletonChartData : chartData
  const resolvedChartInterval = useMemo(
    () => resolveUsageChartIntervalForRange(chartInterval, dateRange),
    [chartInterval, dateRange],
  )
  const axisMax = useMemo(
    () =>
      activeChartData.reduce(
        (max, point) => Math.max(max, point.total),
        0,
      ),
    [activeChartData],
  )
  const yAxisTickFormatter = useMemo(
    () => createUsageChartAxisTickFormatter('bytes', axisMax),
    [axisMax],
  )
  const renderChart = isPanelVisible && activeChartData.length > 0

  return (
    <div className={cn('flex h-full w-full min-w-0 flex-col', className)}>
      <div className={overviewChartPanelHeaderClass}>
        <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground">
          {t(title)}
        </span>
        <div className={overviewChartPanelHeaderActionsClass}>
          {!showChartSkeleton && chartData.length > 0
            ? STORAGE_SERIES.map((series) => (
                <div key={series.key} className="flex items-center gap-1.5">
                  <div
                    className="h-2 w-2 rounded-full"
                    style={{ backgroundColor: series.color }}
                  />
                  <span className="text-[11px] text-muted-foreground">
                    {t(series.label)}
                  </span>
                </div>
              ))
            : null}
          {projectId ? (
            <OverviewViewAllUsageLink projectId={projectId} tabId="storage" />
          ) : null}
        </div>
      </div>

      <div className={overviewChartPanelBodyClass}>
        {isError ? (
          <div className={overviewChartPanelChartAreaClass}>
            <OverviewChartPanelError
              title={t(errorTitle)}
              message={
                typeof errorMessage === 'string' ? t(errorMessage) : errorMessage
              }
              onRetry={onRetry}
              upgradeOrgId={upgradeOrgId}
            />
          </div>
        ) : (
          <div
            className={cn(
              overviewChartPanelChartAreaClass,
              'text-muted-foreground',
              showChartSkeleton && 'pointer-events-none',
            )}
            aria-busy={showChartSkeleton}
            aria-label={showChartSkeleton ? t('Loading usage data') : undefined}
          >
            {showEmptyState ? (
              <div className={overviewChartPanelEmptyClass}>
                {t('No data for this date range')}
              </div>
            ) : null}
            {renderChart ? (
              <div className={overviewChartPanelChartFillClass}>
                <ResponsiveContainer
                  {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}
                  minHeight={OVERVIEW_CHART_HEIGHT}
                >
                  <AreaChart
                    data={activeChartData}
                    margin={USAGE_CHART_MARGIN}
                  >
                    <defs>
                      {showChartSkeleton ? (
                        <linearGradient
                          id="overview-storage-skeleton-gradient"
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
                        STORAGE_SERIES.map((series) => (
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
                              stopOpacity={0.18}
                            />
                            <stop
                              offset="100%"
                              stopColor={series.color}
                              stopOpacity={0}
                            />
                          </linearGradient>
                        ))
                      )}
                    </defs>
                    <UsageChartXAxis
                      points={activeChartData}
                      dateRange={dateRange}
                      chartInterval={resolvedChartInterval}
                      variant="overview"
                    />
                    <UsageChartYAxis tickFormatter={yAxisTickFormatter} />
                    <Tooltip content={<StorageTooltip />} />
                    {showChartSkeleton ? (
                      <Area
                        type="monotone"
                        dataKey="total"
                        stroke={SKELETON_CHART_STROKE}
                        fill="url(#overview-storage-skeleton-gradient)"
                        strokeWidth={1.5}
                        isAnimationActive={!CHART_ANIMATION_DISABLED}
                      />
                    ) : (
                      STORAGE_SERIES.map((series) => (
                        <Area
                          key={series.key}
                          type="monotone"
                          dataKey={series.key}
                          stackId="storage"
                          stroke={series.color}
                          fill={`url(#${series.gradientId})`}
                          strokeWidth={1.5}
                          isAnimationActive={!CHART_ANIMATION_DISABLED}
                        />
                      ))
                    )}
                  </AreaChart>
                </ResponsiveContainer>
              </div>
            ) : null}
          </div>
        )}
      </div>
    </div>
  )
})
