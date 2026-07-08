'use client'

import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import {
  REALTIME_BANDWIDTH_DESCRIPTION,
  REALTIME_CONNECTIONS_DESCRIPTION,
  REALTIME_DOCS_HREF,
  REALTIME_MESSAGES_DESCRIPTION,
  formatRealtimeBandwidthTotal,
  formatRealtimeBandwidthValue,
  formatRealtimeConnectionsTotal,
  formatRealtimeConnectionsValue,
  formatRealtimeMessagesTotal,
  formatRealtimeMessagesValue,
  getUsageChartPeakValue,
  sumUsageChartPoints,
} from '@/lib/usage/realtime-usage'
import {
  refetchProjectRealtimeUsageQueries,
  useProjectRealtimeBandwidthChart,
  useProjectRealtimeConnectionsChart,
  useProjectRealtimeMessagesChart,
} from '@/lib/react-query/hooks'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { InboundOutboundUsageChartCard } from './_components/InboundOutboundUsageChartCard'
import { shouldShowUsageChartSkeleton } from '@/lib/usage/usage-chart-loading'
import { UsageTimeSeriesChartCard } from './_components/UsageTimeSeriesChartCard'

const REALTIME_USAGE_ERROR = {
  title: "Couldn't load realtime usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type RealtimeSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

export function RealtimeSection({
  projectId,
  dateRange,
  chartInterval,
}: RealtimeSectionProps) {
  const queryClient = useQueryClient()
  const { registerRefreshHandler, unregisterRefreshHandler } = useRefresh()

  const connectionsQuery = useProjectRealtimeConnectionsChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const messagesQuery = useProjectRealtimeMessagesChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const bandwidthQuery = useProjectRealtimeBandwidthChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )

  useEffect(() => {
    registerRefreshHandler(
      () => refetchProjectRealtimeUsageQueries(queryClient, projectId),
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
    void refetchProjectRealtimeUsageQueries(queryClient, projectId)
  }

  const connectionsPoints = connectionsQuery.isError
    ? []
    : (connectionsQuery.data?.chartPoints ?? [])
  const messagesPoints = messagesQuery.isError
    ? []
    : (messagesQuery.data?.chartPoints ?? [])
  const dualBandwidthPoints = bandwidthQuery.isError
    ? []
    : (bandwidthQuery.data?.dualChartPoints ?? [])

  return (
    <div className="space-y-6">
      <UsageTimeSeriesChartCard
        title="Concurrent connections"
        description={REALTIME_CONNECTIONS_DESCRIPTION}
        unitLabel="connections"
        chartGradientId="usage-realtime-connections-gradient"
        total={getUsageChartPeakValue(connectionsPoints)}
        changePercent={connectionsQuery.data?.changePercent ?? 0}
        chartPoints={connectionsPoints}
        isLoading={shouldShowUsageChartSkeleton(
          connectionsQuery.isError,
          connectionsQuery.isLoading,
          connectionsQuery.isPlaceholderData,
        )}
        isError={connectionsQuery.isError}
        queryError={connectionsQuery.error}
        errorTitle={REALTIME_USAGE_ERROR.title}
        errorMessage={REALTIME_USAGE_ERROR.message}
        formatTotal={formatRealtimeConnectionsTotal}
        formatValue={formatRealtimeConnectionsValue}
        onRetry={handleRetryAll}
        docsHref={REALTIME_DOCS_HREF}
      />

      <UsageTimeSeriesChartCard
        title="Messages sent"
        description={REALTIME_MESSAGES_DESCRIPTION}
        unitLabel="messages"
        chartGradientId="usage-realtime-messages-gradient"
        total={sumUsageChartPoints(messagesPoints)}
        changePercent={messagesQuery.data?.changePercent ?? 0}
        chartPoints={messagesPoints}
        isLoading={shouldShowUsageChartSkeleton(
          messagesQuery.isError,
          messagesQuery.isLoading,
          messagesQuery.isPlaceholderData,
        )}
        isError={messagesQuery.isError}
        queryError={messagesQuery.error}
        errorTitle={REALTIME_USAGE_ERROR.title}
        errorMessage={REALTIME_USAGE_ERROR.message}
        formatTotal={formatRealtimeMessagesTotal}
        formatValue={formatRealtimeMessagesValue}
        onRetry={handleRetryAll}
        docsHref={REALTIME_DOCS_HREF}
      />

      <InboundOutboundUsageChartCard
        title="Realtime bandwidth"
        description={REALTIME_BANDWIDTH_DESCRIPTION}
        docsHref={REALTIME_DOCS_HREF}
        total={sumUsageChartPoints(bandwidthQuery.data?.chartPoints ?? [])}
        changePercent={bandwidthQuery.data?.changePercent ?? 0}
        dualChartPoints={dualBandwidthPoints}
        isLoading={shouldShowUsageChartSkeleton(
          bandwidthQuery.isError,
          bandwidthQuery.isLoading,
          bandwidthQuery.isPlaceholderData,
        )}
        isError={bandwidthQuery.isError}
        queryError={bandwidthQuery.error}
        errorTitle={REALTIME_USAGE_ERROR.title}
        errorMessage={REALTIME_USAGE_ERROR.message}
        inboundGradientId="usage-realtime-bandwidth-inbound-gradient"
        outboundGradientId="usage-realtime-bandwidth-outbound-gradient"
        formatTotal={formatRealtimeBandwidthTotal}
        formatValue={formatRealtimeBandwidthValue}
        onRetry={handleRetryAll}
      />
    </div>
  )
}
