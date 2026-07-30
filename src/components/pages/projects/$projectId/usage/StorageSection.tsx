'use client'

import { useEffect, useMemo } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import {
  IMAGE_TRANSFORMATIONS_DESCRIPTION,
  IMAGE_TRANSFORMATIONS_DOCS_HREF,
  OVERVIEW_STORAGE_BREAKDOWN_OPTIONS,
  STORAGE_DOCS_HREF,
  formatImageTransformationsTotal,
  formatImageTransformationsValue,
  formatStorageBytesTotal,
  formatStorageBytesValue,
  getStorageGaugeDisplayTotal,
  type OverviewStorageBreakdownType,
} from '@/lib/usage/storage-usage'
import { topConsumersToBreakdownItems } from '@/lib/usage/compute-usage'
import {
  refetchProjectStorageUsageQueries,
  useProjectImageTransformationsUsage,
  useProjectStorageResourceTypeUsage,
  useUsageResourceBreakdownLookups,
} from '@/lib/react-query/hooks'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { StorageMetricBentoCard } from './_components/StorageMetricBentoCard'
import { shouldShowUsageChartSkeleton } from '@/lib/usage/usage-chart-loading'

const STORAGE_CHART_GRADIENT_IDS: Record<OverviewStorageBreakdownType, string> =
  {
    buckets: 'usage-storage-buckets-gradient',
    databases: 'usage-storage-databases-gradient',
    functions: 'usage-storage-functions-gradient',
    sites: 'usage-storage-sites-gradient',
  }

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

  // One query per resource family of the unified `storage` gauge. Listed
  // explicitly rather than mapped so hook order stays fixed.
  const bucketsQuery = useProjectStorageResourceTypeUsage(
    'buckets',
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const databasesQuery = useProjectStorageResourceTypeUsage(
    'databases',
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const functionsQuery = useProjectStorageResourceTypeUsage(
    'functions',
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const sitesQuery = useProjectStorageResourceTypeUsage(
    'sites',
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

  const storageQueries = useMemo(
    () => ({
      buckets: bucketsQuery,
      databases: databasesQuery,
      functions: functionsQuery,
      sites: sitesQuery,
    }),
    [bucketsQuery, databasesQuery, functionsQuery, sitesQuery],
  )

  const storageCards = useMemo(
    () =>
      OVERVIEW_STORAGE_BREAKDOWN_OPTIONS.map((option) => {
        const query = storageQueries[option.value]
        const chartPoints = query.isError ? [] : (query.data?.chartPoints ?? [])

        return {
          option,
          query,
          chartPoints,
          breakdownItems: query.isError
            ? []
            : topConsumersToBreakdownItems(query.data?.topConsumers ?? []),
        }
      }),
    [storageQueries],
  )

  const imageTransformationsPoints = imageTransformationsQuery.isError
    ? []
    : (imageTransformationsQuery.data?.chartPoints ?? [])
  const imageTransformationsBreakdownItems = useMemo(
    () =>
      imageTransformationsQuery.isError
        ? []
        : topConsumersToBreakdownItems(
            imageTransformationsQuery.data?.topConsumers ?? [],
          ),
    [imageTransformationsQuery.isError, imageTransformationsQuery.data?.topConsumers],
  )

  const breakdownItems = useMemo(() => {
    if (!showBreakdown) return []

    return [
      ...storageCards.flatMap((card) => card.breakdownItems),
      ...imageTransformationsBreakdownItems,
    ]
  }, [showBreakdown, storageCards, imageTransformationsBreakdownItems])

  const { storageLookup, computeLookup, databaseLookup } =
    useUsageResourceBreakdownLookups(
      projectId,
      breakdownItems,
      showBreakdown && breakdownItems.length > 0,
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

  return (
    <div className="space-y-6">
      {storageCards.map(({ option, query, chartPoints, breakdownItems }) => (
        <StorageMetricBentoCard
          key={option.value}
          projectId={projectId}
          title={option.title}
          description={option.description}
          unitLabel="bytes"
          chartGradientId={STORAGE_CHART_GRADIENT_IDS[option.value]}
          chartPoints={chartPoints}
          total={getStorageGaugeDisplayTotal(chartPoints)}
          changePercent={query.data?.changePercent ?? 0}
          isLoading={shouldShowUsageChartSkeleton(
            query.isError,
            query.isLoading,
            query.isPlaceholderData,
          )}
          isError={query.isError}
          queryError={query.error}
          formatTotal={formatStorageBytesTotal}
          formatValue={formatStorageBytesValue}
          axisFormat="bytes"
          showBreakdown={showBreakdown}
          breakdownItems={breakdownItems}
          breakdownLookup={storageLookup}
          computeLookup={computeLookup}
          databaseLookup={databaseLookup}
          onRetry={handleRetryAll}
          docsHref={STORAGE_DOCS_HREF}
        />
      ))}

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
