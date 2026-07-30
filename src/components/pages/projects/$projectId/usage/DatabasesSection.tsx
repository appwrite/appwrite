'use client'

import { useEffect, useMemo, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import type { UsageEventBreakdownDimension } from '@/lib/usage/usage-events-common'
import {
  DATABASE_COLLECTIONS_DESCRIPTION,
  DATABASE_DOCUMENTS_DESCRIPTION,
  DATABASE_READS_DESCRIPTION,
  DATABASE_READS_AND_WRITES_DOCS_HREF,
  DATABASE_ROWS_DOCS_HREF,
  DATABASE_TABLES_DOCS_HREF,
  DATABASE_WRITES_DESCRIPTION,
  formatDatabaseCountTotal,
  formatDatabaseCountValue,
  getUsageChartLatestValue,
  sumUsageChartPoints,
} from '@/lib/usage/database-usage'
import {
  refetchProjectDatabaseUsageQueries,
  useProjectDatabaseCollectionsChart,
  useProjectDatabaseDocumentsChart,
  useProjectDatabaseReadsBreakdowns,
  useProjectDatabaseReadsChart,
  useProjectDatabaseWritesBreakdowns,
  useProjectDatabaseWritesChart,
  useUsageResourceBreakdownLookups,
} from '@/lib/react-query/hooks'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import {
  collectUsageResourceBreakdownItems,
} from '@/lib/usage/usage-resources-breakdown'
import { DatabaseOperationBentoCard } from './_components/DatabaseOperationBentoCard'
import { UsageTimeSeriesChartCard } from './_components/UsageTimeSeriesChartCard'
import { shouldShowUsageChartSkeleton } from '@/lib/usage/usage-chart-loading'
import { UsageBreakdownDrawer } from './_components/UsageBreakdownDrawer'

const DATABASE_USAGE_ERROR = {
  title: "Couldn't load database usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type DatabaseBreakdownDrawerState = {
  operation: 'reads' | 'writes'
  title: string
  description: string
  dimension: UsageEventBreakdownDimension
  labelVariant: 'mono' | 'default'
}

type DatabasesSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

export function DatabasesSection({
  projectId,
  dateRange,
  chartInterval,
}: DatabasesSectionProps) {
  const queryClient = useQueryClient()
  const { registerRefreshHandler, unregisterRefreshHandler } = useRefresh()
  const { disableUsageBreakdownQueries } = useDebugOverrides()
  const showBreakdown = !disableUsageBreakdownQueries
  const [breakdownDrawer, setBreakdownDrawer] =
    useState<DatabaseBreakdownDrawerState | null>(null)

  const readsQuery = useProjectDatabaseReadsChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const writesQuery = useProjectDatabaseWritesChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const collectionsQuery = useProjectDatabaseCollectionsChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const documentsQuery = useProjectDatabaseDocumentsChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )

  const readsBreakdowns = useProjectDatabaseReadsBreakdowns(
    projectId,
    dateRange,
    showBreakdown,
  )
  const writesBreakdowns = useProjectDatabaseWritesBreakdowns(
    projectId,
    dateRange,
    showBreakdown,
  )

  const resourceBreakdownItems = useMemo(
    () =>
      showBreakdown
        ? collectUsageResourceBreakdownItems([
            ...readsBreakdowns,
            ...writesBreakdowns,
          ])
        : [],
    [readsBreakdowns, writesBreakdowns, showBreakdown],
  )

  const { computeLookup, databaseLookup, storageLookup, tableLookup } =
    useUsageResourceBreakdownLookups(
      projectId,
      resourceBreakdownItems,
      showBreakdown && resourceBreakdownItems.length > 0,
    )

  useEffect(() => {
    registerRefreshHandler(
      () => refetchProjectDatabaseUsageQueries(queryClient, projectId),
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
    void refetchProjectDatabaseUsageQueries(queryClient, projectId)
  }

  const readsPoints = readsQuery.isError
    ? []
    : (readsQuery.data?.chartPoints ?? [])
  const writesPoints = writesQuery.isError
    ? []
    : (writesQuery.data?.chartPoints ?? [])
  const collectionsPoints = collectionsQuery.isError
    ? []
    : (collectionsQuery.data?.chartPoints ?? [])
  const documentsPoints = documentsQuery.isError
    ? []
    : (documentsQuery.data?.chartPoints ?? [])

  return (
    <div className="space-y-10">
      <DatabaseOperationBentoCard
        projectId={projectId}
        operation="reads"
        title="Database reads"
        description={DATABASE_READS_DESCRIPTION}
        unitLabel="reads"
        chartGradientId="usage-database-reads-gradient"
        chartPoints={readsPoints}
        total={sumUsageChartPoints(readsPoints)}
        changePercent={readsQuery.data?.changePercent ?? 0}
        isLoading={shouldShowUsageChartSkeleton(
          readsQuery.isError,
          readsQuery.isLoading,
          readsQuery.isPlaceholderData,
        )}
        isError={readsQuery.isError}
        queryError={readsQuery.error}
        showBreakdown={showBreakdown}
        breakdowns={readsBreakdowns}
        computeLookup={computeLookup}
        databaseLookup={databaseLookup}
        storageLookup={storageLookup}
        tableLookup={tableLookup}
        onRetry={handleRetryAll}
        onOpenBreakdownDrawer={setBreakdownDrawer}
        docsHref={DATABASE_READS_AND_WRITES_DOCS_HREF}
      />

      <DatabaseOperationBentoCard
        projectId={projectId}
        operation="writes"
        title="Database writes"
        description={DATABASE_WRITES_DESCRIPTION}
        unitLabel="writes"
        chartGradientId="usage-database-writes-gradient"
        chartPoints={writesPoints}
        total={sumUsageChartPoints(writesPoints)}
        changePercent={writesQuery.data?.changePercent ?? 0}
        isLoading={shouldShowUsageChartSkeleton(
          writesQuery.isError,
          writesQuery.isLoading,
          writesQuery.isPlaceholderData,
        )}
        isError={writesQuery.isError}
        queryError={writesQuery.error}
        showBreakdown={showBreakdown}
        breakdowns={writesBreakdowns}
        computeLookup={computeLookup}
        databaseLookup={databaseLookup}
        storageLookup={storageLookup}
        tableLookup={tableLookup}
        onRetry={handleRetryAll}
        onOpenBreakdownDrawer={setBreakdownDrawer}
        docsHref={DATABASE_READS_AND_WRITES_DOCS_HREF}
      />

      <UsageTimeSeriesChartCard
        title="Collections"
        description={DATABASE_COLLECTIONS_DESCRIPTION}
        unitLabel="collections"
        chartGradientId="usage-database-collections-gradient"
        total={getUsageChartLatestValue(collectionsPoints)}
        changePercent={collectionsQuery.data?.changePercent ?? 0}
        chartPoints={collectionsPoints}
        isLoading={shouldShowUsageChartSkeleton(
          collectionsQuery.isError,
          collectionsQuery.isLoading,
          collectionsQuery.isPlaceholderData,
        )}
        isError={collectionsQuery.isError}
        queryError={collectionsQuery.error}
        errorTitle={DATABASE_USAGE_ERROR.title}
        errorMessage={DATABASE_USAGE_ERROR.message}
        formatTotal={formatDatabaseCountTotal}
        formatValue={formatDatabaseCountValue}
        onRetry={handleRetryAll}
        docsHref={DATABASE_TABLES_DOCS_HREF}
      />

      <UsageTimeSeriesChartCard
        title="Total documents"
        description={DATABASE_DOCUMENTS_DESCRIPTION}
        unitLabel="documents"
        chartGradientId="usage-database-documents-gradient"
        total={getUsageChartLatestValue(documentsPoints)}
        changePercent={documentsQuery.data?.changePercent ?? 0}
        chartPoints={documentsPoints}
        isLoading={shouldShowUsageChartSkeleton(
          documentsQuery.isError,
          documentsQuery.isLoading,
          documentsQuery.isPlaceholderData,
        )}
        isError={documentsQuery.isError}
        queryError={documentsQuery.error}
        errorTitle={DATABASE_USAGE_ERROR.title}
        errorMessage={DATABASE_USAGE_ERROR.message}
        formatTotal={formatDatabaseCountTotal}
        formatValue={formatDatabaseCountValue}
        onRetry={handleRetryAll}
        docsHref={DATABASE_ROWS_DOCS_HREF}
      />

      {breakdownDrawer ? (
        <UsageBreakdownDrawer
          open={breakdownDrawer !== null}
          onOpenChange={(open) => {
            if (!open) setBreakdownDrawer(null)
          }}
          projectId={projectId}
          dateRange={dateRange}
          title={breakdownDrawer.title}
          description={breakdownDrawer.description}
          dimension={breakdownDrawer.dimension}
          labelVariant={breakdownDrawer.labelVariant}
          kind={
            breakdownDrawer.operation === 'reads'
              ? 'database-reads'
              : 'database-writes'
          }
          computeLookup={computeLookup}
          databaseLookup={databaseLookup}
          storageLookup={storageLookup}
          tableLookup={tableLookup}
        />
      ) : null}
    </div>
  )
}
