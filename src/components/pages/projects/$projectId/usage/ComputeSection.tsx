'use client'

import { useEffect, useMemo } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import {
  formatExecutionsAxisValue,
  formatExecutionsTotal,
  formatExecutionsValue,
  sumUsageChartPoints,
} from '@/lib/usage/executions-events'
import {
  formatGbHoursAxisValue,
  formatGbHoursTotal,
  formatGbHoursValue,
} from '@/lib/usage/gb-hours-events'
import {
  COMPUTE_DOCS_HREF,
  COMPUTE_EXECUTIONS_BREAKDOWN_TITLE,
  COMPUTE_EXECUTIONS_DESCRIPTION,
  COMPUTE_GB_HOURS_BREAKDOWN_TITLE,
  COMPUTE_GB_HOURS_DESCRIPTION,
  topConsumersToBreakdownItems,
} from '@/lib/usage/compute-usage'
import {
  refetchProjectComputeUsageQueries,
  useComputeBreakdownResources,
  useProjectExecutionsOverview,
  useProjectGbHoursOverview,
} from '@/lib/react-query/hooks'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { GbHoursUnitInfo } from '../overview/GbHoursUnitInfo'
import { ComputeMetricBentoCard } from './_components/ComputeMetricBentoCard'

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
  const queryClient = useQueryClient()
  const { registerRefreshHandler, unregisterRefreshHandler } = useRefresh()
  const { disableUsageBreakdownQueries } = useDebugOverrides()
  const showBreakdown = !disableUsageBreakdownQueries

  const executionsQuery = useProjectExecutionsOverview(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const gbHoursQuery = useProjectGbHoursOverview(
    projectId,
    dateRange,
    true,
    chartInterval,
  )

  const executionBreakdownIds = useMemo(
    () => executionsQuery.data?.topConsumers.map((item) => item.id) ?? [],
    [executionsQuery.data?.topConsumers],
  )
  const gbHoursBreakdownIds = useMemo(
    () => gbHoursQuery.data?.topConsumers.map((item) => item.id) ?? [],
    [gbHoursQuery.data?.topConsumers],
  )

  const { data: executionBreakdownResources } = useComputeBreakdownResources(
    projectId,
    executionBreakdownIds,
    showBreakdown && executionBreakdownIds.length > 0,
  )
  const { data: gbHoursBreakdownResources } = useComputeBreakdownResources(
    projectId,
    gbHoursBreakdownIds,
    showBreakdown && gbHoursBreakdownIds.length > 0,
  )

  useEffect(() => {
    registerRefreshHandler(
      () => refetchProjectComputeUsageQueries(queryClient, projectId),
      'Usage data',
    )
    return () => unregisterRefreshHandler()
  }, [
    queryClient,
    projectId,
    registerRefreshHandler,
    unregisterRefreshHandler,
  ])

  const handleRetryAll = () => {
    void refetchProjectComputeUsageQueries(queryClient, projectId)
  }

  const executionsPoints = executionsQuery.isError
    ? []
    : (executionsQuery.data?.chartPoints ?? [])
  const gbHoursPoints = gbHoursQuery.isError
    ? []
    : (gbHoursQuery.data?.chartPoints ?? [])

  const executionsBreakdownItems = executionsQuery.isError
    ? []
    : topConsumersToBreakdownItems(executionsQuery.data?.topConsumers ?? [])
  const gbHoursBreakdownItems = gbHoursQuery.isError
    ? []
    : topConsumersToBreakdownItems(gbHoursQuery.data?.topConsumers ?? [])

  return (
    <div className="space-y-6">
      <ComputeMetricBentoCard
        projectId={projectId}
        title="Function executions"
        description={COMPUTE_EXECUTIONS_DESCRIPTION}
        unitLabel="executions"
        chartGradientId="usage-compute-executions-gradient"
        chartPoints={executionsPoints}
        total={sumUsageChartPoints(executionsPoints)}
        changePercent={executionsQuery.data?.changePercent ?? 0}
        isLoading={
          executionsQuery.isLoading &&
          !executionsQuery.isError &&
          !executionsQuery.data
        }
        isError={executionsQuery.isError}
        formatTotal={formatExecutionsTotal}
        formatValue={formatExecutionsValue}
        formatAxisValue={formatExecutionsAxisValue}
        showBreakdown={showBreakdown}
        breakdownTitle={COMPUTE_EXECUTIONS_BREAKDOWN_TITLE}
        breakdownItems={executionsBreakdownItems}
        breakdownLookup={executionBreakdownResources?.resources}
        onRetry={handleRetryAll}
        docsHref={COMPUTE_DOCS_HREF}
      />

      <ComputeMetricBentoCard
        projectId={projectId}
        title="GB-hours"
        description={COMPUTE_GB_HOURS_DESCRIPTION}
        unitLabel="GBH"
        chartGradientId="usage-compute-gb-hours-gradient"
        chartPoints={gbHoursPoints}
        total={sumUsageChartPoints(gbHoursPoints)}
        changePercent={gbHoursQuery.data?.changePercent ?? 0}
        isLoading={
          gbHoursQuery.isLoading &&
          !gbHoursQuery.isError &&
          !gbHoursQuery.data
        }
        isError={gbHoursQuery.isError}
        formatTotal={formatGbHoursTotal}
        formatValue={formatGbHoursValue}
        formatAxisValue={formatGbHoursAxisValue}
        showBreakdown={showBreakdown}
        breakdownTitle={COMPUTE_GB_HOURS_BREAKDOWN_TITLE}
        breakdownItems={gbHoursBreakdownItems}
        breakdownLookup={gbHoursBreakdownResources?.resources}
        breakdownTitleAddon={<GbHoursUnitInfo />}
        onRetry={handleRetryAll}
        docsHref={COMPUTE_DOCS_HREF}
      />
    </div>
  )
}
