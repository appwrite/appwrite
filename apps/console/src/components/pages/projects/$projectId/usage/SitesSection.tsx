'use client'

import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { ComputeUsageSection } from './_components/ComputeUsageSection'

type SitesSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

export function SitesSection({
  projectId,
  dateRange,
  chartInterval,
}: SitesSectionProps) {
  return (
    <ComputeUsageSection
      projectId={projectId}
      dateRange={dateRange}
      chartInterval={chartInterval}
      scope="sites"
    />
  )
}
