'use client'

import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { ComputeUsageSection } from './_components/ComputeUsageSection'

type ComputeSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

export function ComputeSection({
  projectId,
  dateRange,
  chartInterval,
}: ComputeSectionProps) {
  return (
    <ComputeUsageSection
      projectId={projectId}
      dateRange={dateRange}
      chartInterval={chartInterval}
      scope="combined"
    />
  )
}
