'use client'

import { useEffect, useMemo } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import type { DateRange } from 'react-day-picker'
import { cn } from '@/lib/utils'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import type { UsageChartAxisFormat } from '@/lib/usage/format-metric'
import type { CountryLookups } from '@/lib/locale/country-lookups'
import {
  formatBandwidthTotal,
  formatBandwidthValue,
} from '@/lib/usage/bandwidth-events'
import {
  formatRequestsTotal,
  formatRequestsValue,
} from '@/lib/usage/requests-events'
import {
  formatGbHoursTotal,
  formatGbHoursValue,
} from '@/lib/usage/gb-hours-events'
import { formatCompactCount } from '@/lib/usage/format-metric'
import { COMPUTE_VIDEO_GB_HOURS_DESCRIPTION } from '@/lib/usage/compute-usage'
import {
  VIDEOS_STORAGE_BREAKDOWN_OPTION,
  formatStorageBytesTotal,
  formatStorageBytesValue,
  getStorageGaugeDisplayTotal,
} from '@/lib/usage/storage-usage'
import {
  RENDITIONS_FAILED_DESCRIPTION,
  RENDITIONS_FAILED_EVENT_METRIC,
  RENDITIONS_SUCCESS_DESCRIPTION,
  RENDITIONS_SUCCESS_EVENT_METRIC,
  VIDEOS_BANDWIDTH_DESCRIPTION,
  VIDEOS_BANDWIDTH_EVENT_METRIC,
  VIDEOS_BREAKDOWN_TITLES,
  VIDEOS_COUNT_DESCRIPTION,
  VIDEOS_DOCS_HREF,
  VIDEOS_REQUESTS_DESCRIPTION,
  VIDEOS_REQUESTS_EVENT_METRIC,
  type VideosTrafficEventMetric,
} from '@/lib/usage/videos-usage'
import { sumUsageChartPoints } from '@/lib/usage/usage-events-common'
import { getUsageChartLatestValue } from '@/lib/usage/database-usage'
import type { UsageBreakdownItem } from '@/lib/usage/requests-breakdowns'
import { canShowUsageCategoryBreakdowns } from '@/lib/usage/aggregate-only-metrics'
import { getUsageChartLoadingProps } from '@/lib/usage/usage-chart-loading'
import {
  refetchProjectVideosUsageQueries,
  useCountryLookups,
  useOrganizationPlan,
  useProject,
  useProjectRenditionsChart,
  useProjectStorageResourceTypeUsage,
  useProjectVideoGbHoursOverview,
  useProjectVideosCountChart,
  useProjectVideosTrafficBreakdown,
  useProjectVideosTrafficChart,
} from '@/lib/react-query/hooks'
import { videosQueryOptions } from '@/lib/react-query/hooks/videos'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { useUsageFilters } from './usage-filters-context'
import { UsageTimeSeriesChartCard } from './_components/UsageTimeSeriesChartCard'
import { UsageResourceBreakdownCard } from './_components/UsageResourceBreakdownCard'
import { StorageMetricBentoCard } from './_components/StorageMetricBentoCard'
import { ComputeMetricBentoCard } from './_components/ComputeMetricBentoCard'
import {
  UsageBreakdownCard,
  UsageMetricCardFooter,
  UsageMetricCardShell,
} from './_components/UsageMetricCard'

const VIDEOS_USAGE_ERROR = {
  title: "Couldn't load videos usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type VideosSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

type TrafficMetricConfig = {
  metric: VideosTrafficEventMetric
  title: string
  description: string
  unitLabel: string
  gradientId: string
  axisFormat: UsageChartAxisFormat
  formatTotal: (value: number) => string
  formatValue: (value: number) => string
}

const BANDWIDTH_CONFIG: TrafficMetricConfig = {
  metric: VIDEOS_BANDWIDTH_EVENT_METRIC,
  title: 'Streaming bandwidth',
  description: VIDEOS_BANDWIDTH_DESCRIPTION,
  unitLabel: 'bytes',
  gradientId: 'usage-videos-bandwidth-gradient',
  axisFormat: 'bytes',
  formatTotal: formatBandwidthTotal,
  formatValue: formatBandwidthValue,
}

const REQUESTS_CONFIG: TrafficMetricConfig = {
  metric: VIDEOS_REQUESTS_EVENT_METRIC,
  title: 'Requests',
  description: VIDEOS_REQUESTS_DESCRIPTION,
  unitLabel: 'requests',
  gradientId: 'usage-videos-requests-gradient',
  axisFormat: 'count',
  formatTotal: formatRequestsTotal,
  formatValue: formatRequestsValue,
}

function formatCount(value: number): string {
  return formatCompactCount(value, { compact: true })
}

export function VideosSection({
  projectId,
  dateRange,
  chartInterval,
}: VideosSectionProps) {
  const queryClient = useQueryClient()
  const { registerRefreshHandler, unregisterRefreshHandler } = useRefresh()
  const { onDateRangeChange } = useUsageFilters()
  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const showBreakdown = canShowUsageCategoryBreakdowns(
    'videos',
    organizationPlan,
  )
  const { lookups: countryLookups } = useCountryLookups()

  const bandwidthQuery = useProjectVideosTrafficChart(
    projectId,
    VIDEOS_BANDWIDTH_EVENT_METRIC,
    dateRange,
    true,
    chartInterval,
  )
  const bandwidthByVideoQuery = useProjectVideosTrafficBreakdown(
    projectId,
    VIDEOS_BANDWIDTH_EVENT_METRIC,
    'resource',
    dateRange,
    showBreakdown,
  )
  const bandwidthByCountryQuery = useProjectVideosTrafficBreakdown(
    projectId,
    VIDEOS_BANDWIDTH_EVENT_METRIC,
    'country',
    dateRange,
    showBreakdown,
  )
  const bandwidthByPathQuery = useProjectVideosTrafficBreakdown(
    projectId,
    VIDEOS_BANDWIDTH_EVENT_METRIC,
    'path',
    dateRange,
    showBreakdown,
  )
  const requestsQuery = useProjectVideosTrafficChart(
    projectId,
    VIDEOS_REQUESTS_EVENT_METRIC,
    dateRange,
    true,
    chartInterval,
  )
  const requestsByVideoQuery = useProjectVideosTrafficBreakdown(
    projectId,
    VIDEOS_REQUESTS_EVENT_METRIC,
    'resource',
    dateRange,
    showBreakdown,
  )
  const requestsByCountryQuery = useProjectVideosTrafficBreakdown(
    projectId,
    VIDEOS_REQUESTS_EVENT_METRIC,
    'country',
    dateRange,
    showBreakdown,
  )
  const requestsByPathQuery = useProjectVideosTrafficBreakdown(
    projectId,
    VIDEOS_REQUESTS_EVENT_METRIC,
    'path',
    dateRange,
    showBreakdown,
  )
  const renditionsQuery = useProjectRenditionsChart(
    projectId,
    RENDITIONS_SUCCESS_EVENT_METRIC,
    dateRange,
    true,
    chartInterval,
  )
  const renditionsFailedQuery = useProjectRenditionsChart(
    projectId,
    RENDITIONS_FAILED_EVENT_METRIC,
    dateRange,
    true,
    chartInterval,
  )
  // Encode compute and storage are project totals: their events and gauge
  // rows carry no video ID, so these cards have no per-video breakdown.
  const gbHoursQuery = useProjectVideoGbHoursOverview(
    projectId,
    dateRange,
    true,
    chartInterval,
    false,
  )
  const storageQuery = useProjectStorageResourceTypeUsage(
    'videos',
    projectId,
    dateRange,
    true,
    chartInterval,
    false,
  )
  const countQuery = useProjectVideosCountChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )

  // Breakdown rows carry video IDs; fetch the names of the ones shown.
  const videoIds = useMemo(() => {
    const ids = new Set<string>()
    for (const item of [
      ...(bandwidthByVideoQuery.data ?? []),
      ...(requestsByVideoQuery.data ?? []),
    ]) {
      if (item.resourceId) ids.add(item.resourceId)
    }
    return [...ids].sort()
  }, [bandwidthByVideoQuery.data, requestsByVideoQuery.data])

  const videoNamesQuery = useQuery({
    ...videosQueryOptions(projectId, 0, Math.max(videoIds.length, 1), undefined, [
      Query.equal('$id', videoIds),
    ]),
    enabled: showBreakdown && videoIds.length > 0,
  })
  const videoNames = useMemo(
    () =>
      new Map(
        (videoNamesQuery.data?.videos ?? []).map((video) => [
          video.$id,
          video.name,
        ]),
      ),
    [videoNamesQuery.data],
  )
  const videoNamesResolved = videoIds.length === 0 || !videoNamesQuery.isPending

  useEffect(() => {
    registerRefreshHandler(
      () => refetchProjectVideosUsageQueries(queryClient, projectId),
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
    void refetchProjectVideosUsageQueries(queryClient, projectId)
  }

  const renditionsPoints = renditionsQuery.isError
    ? []
    : (renditionsQuery.data?.chartPoints ?? [])
  const renditionsFailedPoints = renditionsFailedQuery.isError
    ? []
    : (renditionsFailedQuery.data?.chartPoints ?? [])
  const gbHoursPoints = gbHoursQuery.isError
    ? []
    : (gbHoursQuery.data?.chartPoints ?? [])
  const storagePoints = storageQuery.isError
    ? []
    : (storageQuery.data?.chartPoints ?? [])
  const countPoints = countQuery.isError
    ? []
    : (countQuery.data?.chartPoints ?? [])

  const renditionsLoading = getUsageChartLoadingProps(
    renditionsQuery,
    renditionsPoints,
  )
  const renditionsFailedLoading = getUsageChartLoadingProps(
    renditionsFailedQuery,
    renditionsFailedPoints,
  )
  const gbHoursLoading = getUsageChartLoadingProps(gbHoursQuery, gbHoursPoints)
  const storageLoading = getUsageChartLoadingProps(storageQuery, storagePoints)
  const countLoading = getUsageChartLoadingProps(countQuery, countPoints)

  return (
    <div className="space-y-6">
      {[
        {
          config: BANDWIDTH_CONFIG,
          chartQuery: bandwidthQuery,
          byVideoQuery: bandwidthByVideoQuery,
          byCountryQuery: bandwidthByCountryQuery,
          byPathQuery: bandwidthByPathQuery,
        },
        {
          config: REQUESTS_CONFIG,
          chartQuery: requestsQuery,
          byVideoQuery: requestsByVideoQuery,
          byCountryQuery: requestsByCountryQuery,
          byPathQuery: requestsByPathQuery,
        },
      ].map(
        ({ config, chartQuery, byVideoQuery, byCountryQuery, byPathQuery }) => (
          <VideosTrafficMetric
            key={config.metric}
            config={config}
            chartQuery={chartQuery}
            byVideoQuery={byVideoQuery}
            byCountryQuery={byCountryQuery}
            byPathQuery={byPathQuery}
            videoNames={videoNames}
            videoNamesResolved={videoNamesResolved}
            countryLookups={countryLookups}
            showBreakdown={showBreakdown}
            dateRange={dateRange}
            chartInterval={chartInterval}
            onDateRangeChange={onDateRangeChange}
            onRetry={handleRetryAll}
          />
        ),
      )}

      <div className="grid items-stretch gap-6 lg:grid-cols-2">
        <UsageTimeSeriesChartCard
          title="Renditions encoded"
          description={RENDITIONS_SUCCESS_DESCRIPTION}
          unitLabel="renditions"
          chartGradientId="usage-videos-renditions-gradient"
          total={sumUsageChartPoints(renditionsPoints)}
          changePercent={renditionsQuery.data?.changePercent ?? 0}
          chartPoints={renditionsPoints}
          isLoading={renditionsLoading.isLoading}
          isRefreshing={renditionsLoading.isRefreshing}
          isError={renditionsQuery.isError}
          queryError={renditionsQuery.error}
          errorTitle={VIDEOS_USAGE_ERROR.title}
          errorMessage={VIDEOS_USAGE_ERROR.message}
          formatTotal={formatCount}
          formatValue={formatCount}
          onRetry={handleRetryAll}
          docsHref={VIDEOS_DOCS_HREF}
        />

        <UsageTimeSeriesChartCard
          title="Failed renditions"
          description={RENDITIONS_FAILED_DESCRIPTION}
          unitLabel="renditions"
          chartGradientId="usage-videos-renditions-failed-gradient"
          total={sumUsageChartPoints(renditionsFailedPoints)}
          changePercent={renditionsFailedQuery.data?.changePercent ?? 0}
          chartPoints={renditionsFailedPoints}
          isLoading={renditionsFailedLoading.isLoading}
          isRefreshing={renditionsFailedLoading.isRefreshing}
          isError={renditionsFailedQuery.isError}
          queryError={renditionsFailedQuery.error}
          errorTitle={VIDEOS_USAGE_ERROR.title}
          errorMessage={VIDEOS_USAGE_ERROR.message}
          formatTotal={formatCount}
          formatValue={formatCount}
          onRetry={handleRetryAll}
          docsHref={VIDEOS_DOCS_HREF}
        />
      </div>

      <ComputeMetricBentoCard
        projectId={projectId}
        title="Encoding GB-hours"
        description={COMPUTE_VIDEO_GB_HOURS_DESCRIPTION}
        unitLabel="GBH"
        chartGradientId="usage-videos-gb-hours-gradient"
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
        showBreakdown={false}
        breakdownItems={[]}
        breakdownLookup={undefined}
        onRetry={handleRetryAll}
        docsHref={VIDEOS_DOCS_HREF}
        dateRange={dateRange}
        chartInterval={chartInterval}
        onDateRangeChange={onDateRangeChange}
      />

      <StorageMetricBentoCard
        projectId={projectId}
        title="Video storage"
        description={VIDEOS_STORAGE_BREAKDOWN_OPTION.description}
        unitLabel="bytes"
        chartGradientId="usage-videos-storage-gradient"
        chartPoints={storagePoints}
        total={getStorageGaugeDisplayTotal(storagePoints)}
        changePercent={storageQuery.data?.changePercent ?? 0}
        isLoading={storageLoading.isLoading}
        isRefreshing={storageLoading.isRefreshing}
        isError={storageQuery.isError}
        queryError={storageQuery.error}
        formatTotal={formatStorageBytesTotal}
        formatValue={formatStorageBytesValue}
        axisFormat="bytes"
        showBreakdown={false}
        breakdownItems={[]}
        breakdownLookup={undefined}
        onRetry={handleRetryAll}
        docsHref={VIDEOS_DOCS_HREF}
      />

      <UsageTimeSeriesChartCard
        title="Videos"
        description={VIDEOS_COUNT_DESCRIPTION}
        unitLabel="videos"
        chartGradientId="usage-videos-count-gradient"
        total={getUsageChartLatestValue(countPoints)}
        changePercent={countQuery.data?.changePercent ?? 0}
        chartPoints={countPoints}
        isLoading={countLoading.isLoading}
        isRefreshing={countLoading.isRefreshing}
        isError={countQuery.isError}
        queryError={countQuery.error}
        errorTitle={VIDEOS_USAGE_ERROR.title}
        errorMessage={VIDEOS_USAGE_ERROR.message}
        formatTotal={formatCount}
        formatValue={formatCount}
        onRetry={handleRetryAll}
        docsHref={VIDEOS_DOCS_HREF}
      />
    </div>
  )
}

type ChartQuery = ReturnType<typeof useProjectVideosTrafficChart>
type BreakdownQuery = ReturnType<typeof useProjectVideosTrafficBreakdown>

type VideosTrafficMetricProps = {
  config: TrafficMetricConfig
  chartQuery: ChartQuery
  byVideoQuery: BreakdownQuery
  byCountryQuery: BreakdownQuery
  byPathQuery: BreakdownQuery
  videoNames: Map<string, string>
  videoNamesResolved: boolean
  countryLookups: CountryLookups | null
  showBreakdown: boolean
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
  onDateRangeChange: (dateRange: DateRange | undefined) => void
  onRetry: () => void
}

/** One traffic metric: chart beside its top videos, then countries and endpoints. */
function VideosTrafficMetric({
  config,
  chartQuery,
  byVideoQuery,
  byCountryQuery,
  byPathQuery,
  videoNames,
  videoNamesResolved,
  countryLookups,
  showBreakdown,
  dateRange,
  chartInterval,
  onDateRangeChange,
  onRetry,
}: VideosTrafficMetricProps) {
  const chartPoints = chartQuery.isError
    ? []
    : (chartQuery.data?.chartPoints ?? [])
  const chartLoading = getUsageChartLoadingProps(chartQuery, chartPoints)

  const videoItems = useMemo<UsageBreakdownItem[]>(
    () =>
      (byVideoQuery.isError ? [] : (byVideoQuery.data ?? [])).map((item) => {
        const name = item.resourceId ? videoNames.get(item.resourceId) : null
        return name ? { ...item, label: name } : item
      }),
    [byVideoQuery.isError, byVideoQuery.data, videoNames],
  )

  return (
    <div className="space-y-6">
      <UsageMetricCardShell>
        <div
          className={cn(
            'grid grid-cols-1 items-stretch divide-y divide-border lg:divide-y-0',
            showBreakdown && 'lg:grid-cols-3 lg:divide-x',
          )}
        >
          <div className={cn(showBreakdown && 'lg:col-span-2')}>
            <UsageTimeSeriesChartCard
              embedded
              title={config.title}
              description={config.description}
              unitLabel={config.unitLabel}
              chartGradientId={config.gradientId}
              total={sumUsageChartPoints(chartPoints)}
              changePercent={chartQuery.data?.changePercent ?? 0}
              chartPoints={chartPoints}
              isLoading={chartLoading.isLoading}
              isRefreshing={chartLoading.isRefreshing}
              isError={chartQuery.isError}
              queryError={chartQuery.error}
              errorTitle={VIDEOS_USAGE_ERROR.title}
              errorMessage={VIDEOS_USAGE_ERROR.message}
              formatTotal={config.formatTotal}
              formatValue={config.formatValue}
              axisFormat={config.axisFormat}
              onRetry={onRetry}
              dateRange={dateRange}
              chartInterval={chartInterval}
              onDateRangeChange={onDateRangeChange}
            />
          </div>

          {showBreakdown ? (
            <UsageResourceBreakdownCard
              embedded
              title={VIDEOS_BREAKDOWN_TITLES.resource}
              description={config.description}
              items={videoItems}
              isLoading={byVideoQuery.isLoading}
              isError={byVideoQuery.isError}
              error={byVideoQuery.error}
              resourceNamesResolved={videoNamesResolved}
              errorTitle={VIDEOS_USAGE_ERROR.title}
              errorMessage={VIDEOS_USAGE_ERROR.message}
              formatValue={config.formatValue}
              onRetry={onRetry}
            />
          ) : null}
        </div>

        <UsageMetricCardFooter
          description={config.description}
          docsHref={VIDEOS_DOCS_HREF}
        />
      </UsageMetricCardShell>

      {showBreakdown ? (
        <div className="grid items-stretch gap-6 lg:grid-cols-2">
          <UsageBreakdownCard
            title={VIDEOS_BREAKDOWN_TITLES.country}
            description={config.description}
            dimension="country"
            items={byCountryQuery.isError ? [] : (byCountryQuery.data ?? [])}
            labelVariant="default"
            countryLookups={countryLookups}
            isLoading={byCountryQuery.isLoading}
            isError={byCountryQuery.isError}
            error={byCountryQuery.error}
            errorTitle={VIDEOS_USAGE_ERROR.title}
            errorMessage={VIDEOS_USAGE_ERROR.message}
            formatValue={config.formatValue}
            onRetry={onRetry}
          />
          <UsageBreakdownCard
            title={VIDEOS_BREAKDOWN_TITLES.path}
            description={config.description}
            dimension="path"
            items={byPathQuery.isError ? [] : (byPathQuery.data ?? [])}
            labelVariant="mono"
            countryLookups={countryLookups}
            isLoading={byPathQuery.isLoading}
            isError={byPathQuery.isError}
            error={byPathQuery.error}
            errorTitle={VIDEOS_USAGE_ERROR.title}
            errorMessage={VIDEOS_USAGE_ERROR.message}
            formatValue={config.formatValue}
            onRetry={onRetry}
          />
        </div>
      ) : null}
    </div>
  )
}
