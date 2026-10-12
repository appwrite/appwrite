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
  COMPUTE_EXECUTIONS_DESCRIPTION,
  COMPUTE_FUNCTION_EXECUTIONS_DESCRIPTION,
  COMPUTE_FUNCTION_GB_HOURS_DESCRIPTION,
  COMPUTE_FUNCTIONS_DOCS_HREF,
  COMPUTE_GB_HOURS_DESCRIPTION,
  COMPUTE_SITE_EXECUTIONS_DESCRIPTION,
  COMPUTE_SITE_GB_HOURS_DESCRIPTION,
  COMPUTE_SITES_DOCS_HREF,
  topConsumersToBreakdownItems,
} from '@/lib/usage/compute-usage'
import {
  refetchProjectComputeUsageQueries,
  useProjectExecutionsOverview,
  useProjectFunctionExecutionsOverview,
  useProjectFunctionGbHoursOverview,
  useProjectGbHoursOverview,
  useProjectSiteExecutionsOverview,
  useProjectSiteGbHoursOverview,
  useUsageResourceBreakdownLookups,
} from '@/lib/react-query/hooks'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { GbHoursUnitInfo } from '../../overview/GbHoursUnitInfo'
import { ComputeMetricBentoCard } from './ComputeMetricBentoCard'
import { getUsageChartLoadingProps } from '@/lib/usage/usage-chart-loading'

export type ComputeUsageScope = 'combined' | 'functions' | 'sites'

type ComputeUsageSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
  scope?: ComputeUsageScope
  onDateRangeChange?: (dateRange: DateRange | undefined) => void
}

export function ComputeUsageSection({
  projectId,
  dateRange,
  chartInterval,
  scope = 'combined',
  onDateRangeChange,
}: ComputeUsageSectionProps) {
  const queryClient = useQueryClient()
  const { registerRefreshHandler, unregisterRefreshHandler } = useRefresh()

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
  const allSitesExecutionsQuery = useProjectSiteExecutionsOverview(
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
  const allSitesGbHoursQuery = useProjectSiteGbHoursOverview(
    projectId,
    dateRange,
    scope === 'sites',
    chartInterval,
  )

  const executionsQuery =
    scope === 'functions'
      ? functionExecutionsQuery
      : scope === 'sites'
        ? allSitesExecutionsQuery
        : combinedExecutionsQuery
  const gbHoursQuery =
    scope === 'functions'
      ? functionGbHoursQuery
      : scope === 'sites'
        ? allSitesGbHoursQuery
        : combinedGbHoursQuery

  const breakdownItems = useMemo(() => {
    return [
      ...(executionsQuery.isError
        ? []
        : topConsumersToBreakdownItems(
            executionsQuery.data?.topConsumers ?? [],
          )),
      ...(gbHoursQuery.isError
        ? []
        : topConsumersToBreakdownItems(gbHoursQuery.data?.topConsumers ?? [])),
    ]
  }, [
    executionsQuery.data?.topConsumers,
    executionsQuery.isError,
    gbHoursQuery.data?.topConsumers,
    gbHoursQuery.isError,
  ])

  const { computeLookup } = useUsageResourceBreakdownLookups(
    projectId,
    breakdownItems,
    breakdownItems.length > 0,
  )

  useEffect(() => {
    registerRefreshHandler(
      () => refetchProjectComputeUsageQueries(queryClient, projectId),
      'Usage data',
    )
    return () => unregisterRefreshHandler()
  }, [queryClient, projectId, registerRefreshHandler, unregisterRefreshHandler])

  const handleRetryAll = () => {
    void refetchProjectComputeUsageQueries(queryClient, projectId)
  }

  const executionsPoints = executionsQuery.isError
    ? []
    : (executionsQuery.data?.chartPoints ?? [])
  const gbHoursPoints = gbHoursQuery.isError
    ? []
    : (gbHoursQuery.data?.chartPoints ?? [])

  const executionsLoading = getUsageChartLoadingProps(
    executionsQuery,
    executionsPoints,
  )
  const gbHoursLoading = getUsageChartLoadingProps(gbHoursQuery, gbHoursPoints)

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
        isLoading={executionsLoading.isLoading}
        isRefreshing={executionsLoading.isRefreshing}
        isError={executionsQuery.isError}
        queryError={executionsQuery.error}
        formatTotal={formatExecutionsTotal}
        formatValue={formatExecutionsValue}
        showBreakdown
        breakdownItems={
          executionsQuery.isError
            ? []
            : topConsumersToBreakdownItems(
                executionsQuery.data?.topConsumers ?? [],
              )
        }
        breakdownLookup={computeLookup}
        onRetry={handleRetryAll}
        docsHref={docsHref}
        dateRange={dateRange}
        chartInterval={chartInterval}
        onDateRangeChange={onDateRangeChange}
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
        isLoading={gbHoursLoading.isLoading}
        isRefreshing={gbHoursLoading.isRefreshing}
        isError={gbHoursQuery.isError}
        queryError={gbHoursQuery.error}
        formatTotal={formatGbHoursTotal}
        formatValue={formatGbHoursValue}
        axisFormat="gbhours"
        showBreakdown
        breakdownItems={
          gbHoursQuery.isError
            ? []
            : topConsumersToBreakdownItems(
                gbHoursQuery.data?.topConsumers ?? [],
              )
        }
        breakdownLookup={computeLookup}
        breakdownTitleAddon={<GbHoursUnitInfo />}
        onRetry={handleRetryAll}
        docsHref={docsHref}
        dateRange={dateRange}
        chartInterval={chartInterval}
        onDateRangeChange={onDateRangeChange}
      />
    </div>
  )
}
