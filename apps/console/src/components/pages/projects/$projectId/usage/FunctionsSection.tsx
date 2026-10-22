'use client'

import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { ComputeUsageSection } from './_components/ComputeUsageSection'

type FunctionsSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

export function FunctionsSection({
  projectId,
  dateRange,
  chartInterval,
}: FunctionsSectionProps) {
  return (
    <ComputeUsageSection
      projectId={projectId}
      dateRange={dateRange}
      chartInterval={chartInterval}
      scope="functions"
    />
  )
}
