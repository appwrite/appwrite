'use client'

import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import {
  AVATARS_DOCS_HREF,
  AVATARS_SCREENSHOTS_DESCRIPTION,
  formatAvatarsScreenshotsTotal,
  formatAvatarsScreenshotsValue,
  sumUsageChartPoints,
} from '@/lib/usage/avatars-usage'
import {
  refetchProjectAvatarsUsageQueries,
  useProjectAvatarsScreenshotsChart,
} from '@/lib/react-query/hooks'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { UsageTimeSeriesChartCard } from './_components/UsageTimeSeriesChartCard'
import { shouldShowUsageChartSkeleton } from '@/lib/usage/usage-chart-loading'

const AVATARS_USAGE_ERROR = {
  title: "Couldn't load avatars usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type AvatarsSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

export function AvatarsSection({
  projectId,
  dateRange,
  chartInterval,
}: AvatarsSectionProps) {
  const queryClient = useQueryClient()
  const { registerRefreshHandler, unregisterRefreshHandler } = useRefresh()

  const screenshotsQuery = useProjectAvatarsScreenshotsChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )

  useEffect(() => {
    registerRefreshHandler(
      () => refetchProjectAvatarsUsageQueries(queryClient, projectId),
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
    void refetchProjectAvatarsUsageQueries(queryClient, projectId)
  }

  const chartPoints = screenshotsQuery.isError
    ? []
    : (screenshotsQuery.data?.chartPoints ?? [])

  return (
    <div className="space-y-6">
      <UsageTimeSeriesChartCard
        title="Screenshots generated"
        description={AVATARS_SCREENSHOTS_DESCRIPTION}
        unitLabel="screenshots"
        chartGradientId="usage-avatars-screenshots-gradient"
        total={sumUsageChartPoints(chartPoints)}
        changePercent={screenshotsQuery.data?.changePercent ?? 0}
        chartPoints={chartPoints}
        isLoading={shouldShowUsageChartSkeleton(
          screenshotsQuery.isError,
          screenshotsQuery.isLoading,
          screenshotsQuery.isPlaceholderData,
        )}
        isError={screenshotsQuery.isError}
        queryError={screenshotsQuery.error}
        errorTitle={AVATARS_USAGE_ERROR.title}
        errorMessage={AVATARS_USAGE_ERROR.message}
        formatTotal={formatAvatarsScreenshotsTotal}
        formatValue={formatAvatarsScreenshotsValue}
        onRetry={handleRetryAll}
        docsHref={AVATARS_DOCS_HREF}
      />
    </div>
  )
}
