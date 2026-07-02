'use client'

import { useEffect, useMemo } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import {
  formatExecutionsTotal,
  formatExecutionsValue,
  sumUsageChartPoints,
} from '@/lib/usage/executions-events'
import {
  formatGbHoursTotal,
  formatGbHoursValue,
} from '@/lib/usage/gb-hours-events'
import {
  COMPUTE_EXECUTIONS_BREAKDOWN_TITLE,
  COMPUTE_EXECUTIONS_DESCRIPTION,
  COMPUTE_FUNCTION_EXECUTIONS_BREAKDOWN_TITLE,
  COMPUTE_FUNCTION_EXECUTIONS_DESCRIPTION,
  COMPUTE_FUNCTION_GB_HOURS_BREAKDOWN_TITLE,
  COMPUTE_FUNCTION_GB_HOURS_DESCRIPTION,
  COMPUTE_FUNCTIONS_DOCS_HREF,
  COMPUTE_GB_HOURS_BREAKDOWN_TITLE,
  COMPUTE_GB_HOURS_DESCRIPTION,
  COMPUTE_RESOURCE_TYPES_BREAKDOWN_TITLE,
  COMPUTE_SITE_EXECUTIONS_BREAKDOWN_TITLE,
  COMPUTE_SITE_EXECUTIONS_DESCRIPTION,
  COMPUTE_SITE_GB_HOURS_BREAKDOWN_TITLE,
  COMPUTE_SITE_GB_HOURS_DESCRIPTION,
  COMPUTE_SITES_DOCS_HREF,
  topConsumersToBreakdownItems,
} from '@/lib/usage/compute-usage'
import {
  refetchProjectComputeUsageQueries,
  useComputeBreakdownResources,
  useProjectExecutionsOverview,
  useProjectFunctionExecutionsOverview,
  useProjectFunctionGbHoursOverview,
  useProjectGbHoursOverview,
  useProjectSiteExecutionsOverview,
  useProjectSiteGbHoursOverview,
} from '@/lib/react-query/hooks'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { GbHoursUnitInfo } from '../../overview/GbHoursUnitInfo'
import { ComputeMetricBentoCard } from './ComputeMetricBentoCard'
import { shouldShowUsageChartSkeleton } from '@/lib/usage/usage-chart-loading'

export type ComputeUsageScope = 'combined' | 'functions' | 'sites'

type ComputeUsageSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
  scope?: ComputeUsageScope
}

export function ComputeUsageSection({
  projectId,
  dateRange,
  chartInterval,
  scope = 'combined',
}: ComputeUsageSectionProps) {
  const queryClient = useQueryClient()
  const { registerRefreshHandler, unregisterRefreshHandler } = useRefresh()
  const { disableUsageBreakdownQueries } = useDebugOverrides()
  const showBreakdown = !disableUsageBreakdownQueries

  const combinedExecutionsQuery = useProjectExecutionsOverview(
    projectId,
    dateRange,
    scope === 'combined',
    chartInterval,
  )
  const combinedGbHoursQuery = useProjectGbHoursOverview(
    projectId,
    dateRange,
    scope === 'combined',
    chartInterval,
  )
  const functionExecutionsQuery = useProjectFunctionExecutionsOverview(
    projectId,
    dateRange,
    scope === 'functions',
    chartInterval,
  )
  const siteExecutionsQuery = useProjectSiteExecutionsOverview(
    projectId,
    dateRange,
    scope === 'sites',
    chartInterval,
  )
  const functionGbHoursQuery = useProjectFunctionGbHoursOverview(
    projectId,
    dateRange,
    scope === 'functions',
    chartInterval,
  )
  const siteGbHoursQuery = useProjectSiteGbHoursOverview(
    projectId,
    dateRange,
    scope === 'sites',
    chartInterval,
  )

  const executionsQuery =
    scope === 'functions'
      ? functionExecutionsQuery
      : scope === 'sites'
        ? siteExecutionsQuery
        : combinedExecutionsQuery
  const gbHoursQuery =
    scope === 'functions'
      ? functionGbHoursQuery
      : scope === 'sites'
        ? siteGbHoursQuery
        : combinedGbHoursQuery

  const breakdownResourceIds = useMemo(() => {
    if (!showBreakdown) return []

    const ids = new Set<string>()
    for (const item of [
      ...(executionsQuery.data?.topConsumers ?? []),
      ...(gbHoursQuery.data?.topConsumers ?? []),
    ]) {
      if (item.id) ids.add(item.id)
    }
    return Array.from(ids)
  }, [
    executionsQuery.data?.topConsumers,
    gbHoursQuery.data?.topConsumers,
    showBreakdown,
  ])

  const { data: breakdownResources } = useComputeBreakdownResources(
    projectId,
    breakdownResourceIds,
    showBreakdown && breakdownResourceIds.length > 0,
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

  const executionsTitle =
    scope === 'functions'
      ? 'Function executions'
      : scope === 'sites'
        ? 'Site executions'
        : 'Executions'
  const gbHoursTitle =
    scope === 'functions'
      ? 'Function GB-hours'
      : scope === 'sites'
        ? 'Site GB-hours'
        : 'GB-hours'

  const executionsDescription =
    scope === 'functions'
      ? COMPUTE_FUNCTION_EXECUTIONS_DESCRIPTION
      : scope === 'sites'
        ? COMPUTE_SITE_EXECUTIONS_DESCRIPTION
        : COMPUTE_EXECUTIONS_DESCRIPTION
  const gbHoursDescription =
    scope === 'functions'
      ? COMPUTE_FUNCTION_GB_HOURS_DESCRIPTION
      : scope === 'sites'
        ? COMPUTE_SITE_GB_HOURS_DESCRIPTION
        : COMPUTE_GB_HOURS_DESCRIPTION

  const executionsBreakdownTitle =
    scope === 'functions'
      ? COMPUTE_FUNCTION_EXECUTIONS_BREAKDOWN_TITLE
      : scope === 'sites'
        ? COMPUTE_SITE_EXECUTIONS_BREAKDOWN_TITLE
        : COMPUTE_EXECUTIONS_BREAKDOWN_TITLE
  const gbHoursBreakdownTitle =
    scope === 'functions'
      ? COMPUTE_FUNCTION_GB_HOURS_BREAKDOWN_TITLE
      : scope === 'sites'
        ? COMPUTE_SITE_GB_HOURS_BREAKDOWN_TITLE
        : COMPUTE_GB_HOURS_BREAKDOWN_TITLE

  const docsHref =
    scope === 'sites' ? COMPUTE_SITES_DOCS_HREF : COMPUTE_FUNCTIONS_DOCS_HREF

  const scopeKey = scope === 'combined' ? 'compute' : scope

  return (
    <div className="space-y-6">
      <ComputeMetricBentoCard
        projectId={projectId}
        title={executionsTitle}
        description={executionsDescription}
        unitLabel="executions"
        chartGradientId={`usage-${scopeKey}-executions-gradient`}
        chartPoints={executionsPoints}
        total={sumUsageChartPoints(executionsPoints)}
        changePercent={executionsQuery.data?.changePercent ?? 0}
        isLoading={shouldShowUsageChartSkeleton(
          executionsQuery.isError,
          executionsQuery.isLoading,
          executionsQuery.isPlaceholderData,
        )}
        isError={executionsQuery.isError}
        formatTotal={formatExecutionsTotal}
        formatValue={formatExecutionsValue}
        showBreakdown={showBreakdown}
        breakdownTitle={executionsBreakdownTitle}
        breakdownItems={
          executionsQuery.isError
            ? []
            : topConsumersToBreakdownItems(
                executionsQuery.data?.topConsumers ?? [],
              )
        }
        resourceTypeBreakdownTitle={COMPUTE_RESOURCE_TYPES_BREAKDOWN_TITLE}
        resourceTypeBreakdownItems={
          executionsQuery.isError
            ? []
            : (executionsQuery.data?.resourceTypeBreakdown ?? [])
        }
        breakdownLookup={breakdownResources?.resources}
        onRetry={handleRetryAll}
        docsHref={docsHref}
      />

      <ComputeMetricBentoCard
        projectId={projectId}
        title={gbHoursTitle}
        description={gbHoursDescription}
        unitLabel="GBH"
        chartGradientId={`usage-${scopeKey}-gb-hours-gradient`}
        chartPoints={gbHoursPoints}
        total={sumUsageChartPoints(gbHoursPoints)}
        changePercent={gbHoursQuery.data?.changePercent ?? 0}
        isLoading={shouldShowUsageChartSkeleton(
          gbHoursQuery.isError,
          gbHoursQuery.isLoading,
          gbHoursQuery.isPlaceholderData,
        )}
        isError={gbHoursQuery.isError}
        formatTotal={formatGbHoursTotal}
        formatValue={formatGbHoursValue}
        axisFormat="gbhours"
        showBreakdown={showBreakdown}
        breakdownTitle={gbHoursBreakdownTitle}
        breakdownItems={
          gbHoursQuery.isError
            ? []
            : topConsumersToBreakdownItems(gbHoursQuery.data?.topConsumers ?? [])
        }
        resourceTypeBreakdownTitle={COMPUTE_RESOURCE_TYPES_BREAKDOWN_TITLE}
        resourceTypeBreakdownItems={
          gbHoursQuery.isError
            ? []
            : (gbHoursQuery.data?.resourceTypeBreakdown ?? [])
        }
        breakdownLookup={breakdownResources?.resources}
        breakdownTitleAddon={<GbHoursUnitInfo />}
        onRetry={handleRetryAll}
        docsHref={docsHref}
      />
    </div>
  )
}
