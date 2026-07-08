'use client'

import { cn } from '@/lib/utils'
import type { UsageChartAxisFormat } from '@/lib/usage/format-metric'
import type { StorageBreakdownResourceMap } from '@/lib/usage/resolve-storage-breakdown-resources'
import type { UsageBreakdownItem } from '@/lib/usage/requests-breakdowns'
import { UsageTimeSeriesChartCard } from './UsageTimeSeriesChartCard'
import {
  UsageMetricCardFooter,
  UsageMetricCardShell,
} from './UsageMetricCard'
import { UsageResourceBreakdownCard } from './UsageResourceBreakdownCard'

const STORAGE_USAGE_ERROR = {
  title: "Couldn't load storage usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type StorageMetricBentoCardProps = {
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
  formatTotal: (value: number) => string
  formatValue: (value: number) => string
  axisFormat?: UsageChartAxisFormat
  showBreakdown: boolean
  breakdownItems: UsageBreakdownItem[]
  resourceTypeBreakdownItems: UsageBreakdownItem[]
  breakdownLookup: StorageBreakdownResourceMap | undefined
  onRetry: () => void
  docsHref?: string
}

export function StorageMetricBentoCard({
  projectId,
  title,
  description,
  unitLabel,
  chartGradientId,
  chartPoints,
  total,
  changePercent,
  isLoading,
  isError,
  formatTotal,
  formatValue,
  axisFormat = 'bytes',
  showBreakdown,
  breakdownItems,
  resourceTypeBreakdownItems,
  breakdownLookup,
  onRetry,
  docsHref,
}: StorageMetricBentoCardProps) {
  return (
    <UsageMetricCardShell>
      <div
        className={cn(
          'grid grid-cols-1 items-stretch divide-y divide-border lg:grid-cols-3 lg:divide-x lg:divide-y-0',
        )}
      >
        <div className="lg:col-span-2">
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
            errorTitle={STORAGE_USAGE_ERROR.title}
            errorMessage={STORAGE_USAGE_ERROR.message}
            formatTotal={formatTotal}
            formatValue={formatValue}
            axisFormat={axisFormat}
            onRetry={onRetry}
          />
        </div>

        {showBreakdown ? (
          <UsageResourceBreakdownCard
            embedded
            description={description}
            resourceIdView={{
              items: breakdownItems,
              isLoading,
              isError,
            }}
            resourceTypeView={{
              items: resourceTypeBreakdownItems,
              isLoading,
              isError,
            }}
            storageLookup={breakdownLookup}
            errorTitle={STORAGE_USAGE_ERROR.title}
            errorMessage={STORAGE_USAGE_ERROR.message}
            formatValue={formatValue}
            onRetry={onRetry}
          />
        ) : null}
      </div>

      <UsageMetricCardFooter description={description} docsHref={docsHref} />
    </UsageMetricCardShell>
  )
}
