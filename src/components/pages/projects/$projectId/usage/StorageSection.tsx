'use client'

import { useEffect, useMemo } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import {
  IMAGE_TRANSFORMATIONS_DESCRIPTION,
  IMAGE_TRANSFORMATIONS_DOCS_HREF,
  STORAGE_BUILDS_DESCRIPTION,
  STORAGE_DEPLOYMENTS_DESCRIPTION,
  STORAGE_DOCS_HREF,
  STORAGE_FILE_DESCRIPTION,
  formatImageTransformationsTotal,
  formatImageTransformationsValue,
  formatStorageBytesTotal,
  formatStorageBytesValue,
  getStorageGaugeDisplayTotal,
} from '@/lib/usage/storage-usage'
import { topConsumersToBreakdownItems } from '@/lib/usage/compute-usage'
import {
  refetchProjectStorageUsageQueries,
  useProjectImageTransformationsUsage,
  useProjectStorageBuildsChart,
  useProjectStorageDeploymentsChart,
  useProjectStorageFilesUsage,
  useUsageResourceBreakdownLookups,
} from '@/lib/react-query/hooks'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { getUsageBreakdownResourceIds } from '@/lib/usage/usage-resources-breakdown'
import { StorageMetricBentoCard } from './_components/StorageMetricBentoCard'
import { UsageTimeSeriesChartCard } from './_components/UsageTimeSeriesChartCard'
import { shouldShowUsageChartSkeleton } from '@/lib/usage/usage-chart-loading'

const STORAGE_USAGE_ERROR = {
  title: "Couldn't load storage usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type StorageSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

export function StorageSection({
  projectId,
  dateRange,
  chartInterval,
}: StorageSectionProps) {
  const queryClient = useQueryClient()
  const { registerRefreshHandler, unregisterRefreshHandler } = useRefresh()
  const { disableUsageBreakdownQueries } = useDebugOverrides()
  const showBreakdown = !disableUsageBreakdownQueries

  const filesQuery = useProjectStorageFilesUsage(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const deploymentsQuery = useProjectStorageDeploymentsChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const buildsQuery = useProjectStorageBuildsChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const imageTransformationsQuery = useProjectImageTransformationsUsage(
    projectId,
    dateRange,
    true,
    chartInterval,
  )

  const breakdownItems = useMemo(() => {
    if (!showBreakdown) return []

    return [
      ...(filesQuery.isError
        ? []
        : topConsumersToBreakdownItems(filesQuery.data?.topConsumers ?? [])),
      ...(imageTransformationsQuery.isError
        ? []
        : topConsumersToBreakdownItems(
            imageTransformationsQuery.data?.topConsumers ?? [],
          )),
    ]
  }, [
    filesQuery.data?.topConsumers,
    filesQuery.isError,
    imageTransformationsQuery.data?.topConsumers,
    imageTransformationsQuery.isError,
    showBreakdown,
  ])

  const breakdownBucketIds = useMemo(
    () => getUsageBreakdownResourceIds(breakdownItems),
    [breakdownItems],
  )

  const { storageLookup } = useUsageResourceBreakdownLookups(
    projectId,
    breakdownBucketIds,
    showBreakdown && breakdownBucketIds.length > 0,
  )

  useEffect(() => {
    registerRefreshHandler(
      () => refetchProjectStorageUsageQueries(queryClient, projectId),
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
    void refetchProjectStorageUsageQueries(queryClient, projectId)
  }

  const filesPoints = filesQuery.isError ? [] : (filesQuery.data?.chartPoints ?? [])
  const deploymentsPoints = deploymentsQuery.isError
    ? []
    : (deploymentsQuery.data?.chartPoints ?? [])
  const buildsPoints = buildsQuery.isError
    ? []
    : (buildsQuery.data?.chartPoints ?? [])
  const imageTransformationsPoints = imageTransformationsQuery.isError
    ? []
    : (imageTransformationsQuery.data?.chartPoints ?? [])

  const filesBreakdownItems = filesQuery.isError
    ? []
    : topConsumersToBreakdownItems(filesQuery.data?.topConsumers ?? [])
  const imageTransformationsBreakdownItems = imageTransformationsQuery.isError
    ? []
    : topConsumersToBreakdownItems(
        imageTransformationsQuery.data?.topConsumers ?? [],
      )

  return (
    <div className="space-y-6">
      <StorageMetricBentoCard
        projectId={projectId}
        title="Files"
        description={STORAGE_FILE_DESCRIPTION}
        unitLabel="bytes"
        chartGradientId="usage-storage-files-gradient"
        chartPoints={filesPoints}
        total={getStorageGaugeDisplayTotal(filesPoints)}
        changePercent={filesQuery.data?.changePercent ?? 0}
        isLoading={shouldShowUsageChartSkeleton(
          filesQuery.isError,
          filesQuery.isLoading,
          filesQuery.isPlaceholderData,
        )}
        isError={filesQuery.isError}
        queryError={filesQuery.error}
        formatTotal={formatStorageBytesTotal}
        formatValue={formatStorageBytesValue}
        axisFormat="bytes"
        showBreakdown={showBreakdown}
        breakdownItems={filesBreakdownItems}
        breakdownLookup={storageLookup}
        onRetry={handleRetryAll}
        docsHref={STORAGE_DOCS_HREF}
      />

      <UsageTimeSeriesChartCard
        title="Deployment storage"
        description={STORAGE_DEPLOYMENTS_DESCRIPTION}
        unitLabel="bytes"
        chartGradientId="usage-storage-deployments-gradient"
        total={getStorageGaugeDisplayTotal(deploymentsPoints)}
        changePercent={deploymentsQuery.data?.changePercent ?? 0}
        chartPoints={deploymentsPoints}
        isLoading={shouldShowUsageChartSkeleton(
          deploymentsQuery.isError,
          deploymentsQuery.isLoading,
          deploymentsQuery.isPlaceholderData,
        )}
        isError={deploymentsQuery.isError}
        queryError={deploymentsQuery.error}
        errorTitle={STORAGE_USAGE_ERROR.title}
        errorMessage={STORAGE_USAGE_ERROR.message}
        formatTotal={formatStorageBytesTotal}
        formatValue={formatStorageBytesValue}
        axisFormat="bytes"
        onRetry={handleRetryAll}
        docsHref={STORAGE_DOCS_HREF}
      />

      <UsageTimeSeriesChartCard
        title="Build storage"
        description={STORAGE_BUILDS_DESCRIPTION}
        unitLabel="bytes"
        chartGradientId="usage-storage-builds-gradient"
        total={getStorageGaugeDisplayTotal(buildsPoints)}
        changePercent={buildsQuery.data?.changePercent ?? 0}
        chartPoints={buildsPoints}
        isLoading={shouldShowUsageChartSkeleton(
          buildsQuery.isError,
          buildsQuery.isLoading,
          buildsQuery.isPlaceholderData,
        )}
        isError={buildsQuery.isError}
        queryError={buildsQuery.error}
        errorTitle={STORAGE_USAGE_ERROR.title}
        errorMessage={STORAGE_USAGE_ERROR.message}
        formatTotal={formatStorageBytesTotal}
        formatValue={formatStorageBytesValue}
        axisFormat="bytes"
        onRetry={handleRetryAll}
        docsHref={STORAGE_DOCS_HREF}
      />

      <StorageMetricBentoCard
        projectId={projectId}
        title="Image transformations"
        description={IMAGE_TRANSFORMATIONS_DESCRIPTION}
        unitLabel="origin images"
        chartGradientId="usage-storage-image-transformations-gradient"
        chartPoints={imageTransformationsPoints}
        total={getStorageGaugeDisplayTotal(imageTransformationsPoints)}
        changePercent={imageTransformationsQuery.data?.changePercent ?? 0}
        isLoading={shouldShowUsageChartSkeleton(
          imageTransformationsQuery.isError,
          imageTransformationsQuery.isLoading,
          imageTransformationsQuery.isPlaceholderData,
        )}
        isError={imageTransformationsQuery.isError}
        queryError={imageTransformationsQuery.error}
        formatTotal={formatImageTransformationsTotal}
        formatValue={formatImageTransformationsValue}
        axisFormat="count"
        showBreakdown={showBreakdown}
        breakdownItems={imageTransformationsBreakdownItems}
        breakdownLookup={storageLookup}
        onRetry={handleRetryAll}
        docsHref={IMAGE_TRANSFORMATIONS_DOCS_HREF}
      />
    </div>
  )
}
