'use client'

import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import {
  WEBHOOKS_COUNT_DESCRIPTION,
  WEBHOOKS_DOCS_HREF,
  WEBHOOKS_EVENTS_FAILED_DESCRIPTION,
  WEBHOOKS_EVENTS_SENT_DESCRIPTION,
  formatWebhooksCountTotal,
  formatWebhooksCountValue,
  getWebhooksCountDisplayTotal,
  sumUsageChartPoints,
} from '@/lib/usage/webhooks-usage'
import {
  refetchProjectWebhooksUsageQueries,
  useProjectWebhooksCountChart,
  useProjectWebhooksEventsFailedChart,
  useProjectWebhooksEventsSentChart,
} from '@/lib/react-query/hooks'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { UsageTimeSeriesChartCard } from './_components/UsageTimeSeriesChartCard'
import { shouldShowUsageChartSkeleton } from '@/lib/usage/usage-chart-loading'

const WEBHOOKS_USAGE_ERROR = {
  title: "Couldn't load webhooks usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type WebhooksSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

export function WebhooksSection({
  projectId,
  dateRange,
  chartInterval,
}: WebhooksSectionProps) {
  const queryClient = useQueryClient()
  const { registerRefreshHandler, unregisterRefreshHandler } = useRefresh()

  const eventsSentQuery = useProjectWebhooksEventsSentChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const eventsFailedQuery = useProjectWebhooksEventsFailedChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const countQuery = useProjectWebhooksCountChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )

  useEffect(() => {
    registerRefreshHandler(
      () => refetchProjectWebhooksUsageQueries(queryClient, projectId),
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
    void refetchProjectWebhooksUsageQueries(queryClient, projectId)
  }

  const eventsSentPoints = eventsSentQuery.isError
    ? []
    : (eventsSentQuery.data?.chartPoints ?? [])
  const eventsFailedPoints = eventsFailedQuery.isError
    ? []
    : (eventsFailedQuery.data?.chartPoints ?? [])
  const countPoints = countQuery.isError
    ? []
    : (countQuery.data?.chartPoints ?? [])

  return (
    <div className="space-y-6">
      <UsageTimeSeriesChartCard
        title="Events sent"
        description={WEBHOOKS_EVENTS_SENT_DESCRIPTION}
        unitLabel="events"
        chartGradientId="usage-webhooks-events-sent-gradient"
        total={sumUsageChartPoints(eventsSentPoints)}
        changePercent={eventsSentQuery.data?.changePercent ?? 0}
        chartPoints={eventsSentPoints}
        isLoading={shouldShowUsageChartSkeleton(
          eventsSentQuery.isError,
          eventsSentQuery.isLoading,
          eventsSentQuery.isPlaceholderData,
        )}
        isError={eventsSentQuery.isError}
        queryError={eventsSentQuery.error}
        errorTitle={WEBHOOKS_USAGE_ERROR.title}
        errorMessage={WEBHOOKS_USAGE_ERROR.message}
        formatTotal={formatWebhooksCountTotal}
        formatValue={formatWebhooksCountValue}
        onRetry={handleRetryAll}
        docsHref={WEBHOOKS_DOCS_HREF}
      />

      <UsageTimeSeriesChartCard
        title="Events failed"
        description={WEBHOOKS_EVENTS_FAILED_DESCRIPTION}
        unitLabel="events"
        chartGradientId="usage-webhooks-events-failed-gradient"
        total={sumUsageChartPoints(eventsFailedPoints)}
        changePercent={eventsFailedQuery.data?.changePercent ?? 0}
        chartPoints={eventsFailedPoints}
        isLoading={shouldShowUsageChartSkeleton(
          eventsFailedQuery.isError,
          eventsFailedQuery.isLoading,
          eventsFailedQuery.isPlaceholderData,
        )}
        isError={eventsFailedQuery.isError}
        queryError={eventsFailedQuery.error}
        errorTitle={WEBHOOKS_USAGE_ERROR.title}
        errorMessage={WEBHOOKS_USAGE_ERROR.message}
        formatTotal={formatWebhooksCountTotal}
        formatValue={formatWebhooksCountValue}
        onRetry={handleRetryAll}
        docsHref={WEBHOOKS_DOCS_HREF}
      />

      <UsageTimeSeriesChartCard
        title="Webhooks"
        description={WEBHOOKS_COUNT_DESCRIPTION}
        unitLabel="webhooks"
        chartGradientId="usage-webhooks-count-gradient"
        total={getWebhooksCountDisplayTotal(countPoints)}
        changePercent={countQuery.data?.changePercent ?? 0}
        chartPoints={countPoints}
        isLoading={shouldShowUsageChartSkeleton(
          countQuery.isError,
          countQuery.isLoading,
          countQuery.isPlaceholderData,
        )}
        isError={countQuery.isError}
        queryError={countQuery.error}
        errorTitle={WEBHOOKS_USAGE_ERROR.title}
        errorMessage={WEBHOOKS_USAGE_ERROR.message}
        formatTotal={formatWebhooksCountTotal}
        formatValue={formatWebhooksCountValue}
        onRetry={handleRetryAll}
        docsHref={WEBHOOKS_DOCS_HREF}
      />
    </div>
  )
}
