'use client'

import { cn } from '@/lib/utils'
import type { UsageChartAxisFormat } from '@/lib/usage/format-metric'
import type { StorageBreakdownResourceMap } from '@/lib/usage/resolve-storage-breakdown-resources'
import type { UsageBreakdownItem } from '@/lib/usage/requests-breakdowns'
import { UsageTimeSeriesChartCard } from './UsageTimeSeriesChartCard'
import {
  UsageBreakdownCard,
  UsageMetricCardFooter,
  UsageMetricCardShell,
} from './UsageMetricCard'

const STORAGE_USAGE_ERROR = {
  title: "Couldn't load storage usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

const breakdownRowGridClass =
  'grid grid-cols-1 divide-y divide-border lg:grid-cols-2 lg:divide-x lg:divide-y-0'

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
  breakdownTitle: string
  breakdownItems: UsageBreakdownItem[]
  resourceTypeBreakdownTitle: string
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
  breakdownTitle,
  breakdownItems,
  resourceTypeBreakdownTitle,
  resourceTypeBreakdownItems,
  breakdownLookup,
  onRetry,
  docsHref,
}: StorageMetricBentoCardProps) {
  return (
    <UsageMetricCardShell>
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

      {showBreakdown ? (
        <div className={cn(breakdownRowGridClass, 'border-t border-border')}>
          <UsageBreakdownCard
            embedded
            title={breakdownTitle}
            description={description}
            dimension="resourceId"
            items={breakdownItems}
            labelVariant="mono"
            countryLookups={null}
            storageLookup={breakdownLookup}
            isLoading={isLoading}
            isError={isError}
            errorTitle={STORAGE_USAGE_ERROR.title}
            errorMessage={STORAGE_USAGE_ERROR.message}
            formatValue={formatValue}
            onRetry={onRetry}
          />
          <UsageBreakdownCard
            embedded
            title={resourceTypeBreakdownTitle}
            description={description}
            dimension="resource"
            items={resourceTypeBreakdownItems}
            labelVariant="default"
            countryLookups={null}
            storageLookup={breakdownLookup}
            isLoading={isLoading}
            isError={isError}
            errorTitle={STORAGE_USAGE_ERROR.title}
            errorMessage={STORAGE_USAGE_ERROR.message}
            formatValue={formatValue}
            onRetry={onRetry}
          />
        </div>
      ) : null}

      <UsageMetricCardFooter description={description} docsHref={docsHref} />
    </UsageMetricCardShell>
  )
}
