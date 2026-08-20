'use client'

import { cn } from '@/lib/utils'
import type { UsageChartAxisFormat } from '@/lib/usage/format-metric'
import type { ComputeBreakdownResourceMap } from '@/lib/usage/resolve-compute-breakdown-resources'
import type { UsageBreakdownItem } from '@/lib/usage/requests-breakdowns'
import { UsageTimeSeriesChartCard } from './UsageTimeSeriesChartCard'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { UsageMetricCardFooter, UsageMetricCardShell } from './UsageMetricCard'
import { UsageResourceBreakdownCard } from './UsageResourceBreakdownCard'

const COMPUTE_USAGE_ERROR = {
  title: "Couldn't load compute usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type ComputeMetricBentoCardProps = {
  projectId: string
  title: string
  description: string
  unitLabel: string
  chartGradientId: string
  chartPoints: { date: string; day: Date; total: number }[]
  total: number
  changePercent: number
  isLoading: boolean
  isError: boolean
  queryError?: unknown
  formatTotal: (value: number) => string
  formatValue: (value: number) => string
  axisFormat?: UsageChartAxisFormat
  showBreakdown: boolean
  breakdownItems: UsageBreakdownItem[]
  breakdownLookup: ComputeBreakdownResourceMap | undefined
  breakdownTitleAddon?: React.ReactNode
  onRetry: () => void
  docsHref?: string
  dateRange?: DateRange
  chartInterval?: UsageChartInterval
  onDateRangeChange?: (dateRange: DateRange | undefined) => void
}

export function ComputeMetricBentoCard({
  title,
  description,
  unitLabel,
  chartGradientId,
  chartPoints,
  total,
  changePercent,
  isLoading,
  isError,
  queryError,
  formatTotal,
  formatValue,
  axisFormat = 'count',
  showBreakdown,
  breakdownItems,
  breakdownLookup,
  breakdownTitleAddon,
  onRetry,
  docsHref,
  dateRange,
  chartInterval,
  onDateRangeChange,
}: ComputeMetricBentoCardProps) {
  return (
    <UsageMetricCardShell>
      <div
        className={cn(
          'grid grid-cols-1 items-stretch divide-y divide-border lg:divide-y-0',
          showBreakdown && 'lg:grid-cols-3 lg:divide-x',
        )}
      >
        <div className={cn(showBreakdown && 'lg:col-span-2')}>
          <UsageTimeSeriesChartCard
            embedded
            title={title}
            description={description}
            unitLabel={unitLabel}
            chartGradientId={chartGradientId}
            total={total}
            changePercent={changePercent}
            chartPoints={chartPoints}
            isLoading={isLoading}
            isError={isError}
            queryError={queryError}
            errorTitle={COMPUTE_USAGE_ERROR.title}
            errorMessage={COMPUTE_USAGE_ERROR.message}
            formatTotal={formatTotal}
            formatValue={formatValue}
            axisFormat={axisFormat}
            onRetry={onRetry}
            dateRange={dateRange}
            chartInterval={chartInterval}
            onDateRangeChange={onDateRangeChange}
          />
        </div>

        {showBreakdown ? (
          <UsageResourceBreakdownCard
            embedded
            description={description}
            items={breakdownItems}
            isLoading={isLoading}
            isError={isError}
            computeLookup={breakdownLookup}
            errorTitle={COMPUTE_USAGE_ERROR.title}
            errorMessage={COMPUTE_USAGE_ERROR.message}
            formatValue={formatValue}
            onRetry={onRetry}
            titleAddon={breakdownTitleAddon}
          />
        ) : null}
      </div>

      <UsageMetricCardFooter description={description} docsHref={docsHref} />
    </UsageMetricCardShell>
  )
}
