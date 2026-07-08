'use client'

import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import {
  MESSAGING_DOCS_HREF,
  MESSAGING_MESSAGES_DESCRIPTION,
  MESSAGING_SMS_DESCRIPTION,
  MESSAGING_TOPICS_DESCRIPTION,
  formatMessagingCountTotal,
  formatMessagingCountValue,
  getMessagingTopicsDisplayTotal,
  sumUsageChartPoints,
} from '@/lib/usage/messaging-usage'
import {
  refetchProjectMessagingUsageQueries,
  useProjectMessagingMessagesChart,
  useProjectMessagingSmsChart,
  useProjectMessagingTopicsChart,
} from '@/lib/react-query/hooks'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { UsageTimeSeriesChartCard } from './_components/UsageTimeSeriesChartCard'
import { shouldShowUsageChartSkeleton } from '@/lib/usage/usage-chart-loading'

const MESSAGING_USAGE_ERROR = {
  title: "Couldn't load messaging usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type MessagingSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

export function MessagingSection({
  projectId,
  dateRange,
  chartInterval,
}: MessagingSectionProps) {
  const queryClient = useQueryClient()
  const { registerRefreshHandler, unregisterRefreshHandler } = useRefresh()

  const messagesQuery = useProjectMessagingMessagesChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const topicsQuery = useProjectMessagingTopicsChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const smsQuery = useProjectMessagingSmsChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )

  useEffect(() => {
    registerRefreshHandler(
      () => refetchProjectMessagingUsageQueries(queryClient, projectId),
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
    void refetchProjectMessagingUsageQueries(queryClient, projectId)
  }

  const messagesPoints = messagesQuery.isError
    ? []
    : (messagesQuery.data?.chartPoints ?? [])
  const topicsPoints = topicsQuery.isError
    ? []
    : (topicsQuery.data?.chartPoints ?? [])
  const smsPoints = smsQuery.isError ? [] : (smsQuery.data?.chartPoints ?? [])

  return (
    <div className="space-y-6">
      <UsageTimeSeriesChartCard
        title="Messages sent"
        description={MESSAGING_MESSAGES_DESCRIPTION}
        unitLabel="messages"
        chartGradientId="usage-messaging-messages-gradient"
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
        errorTitle={MESSAGING_USAGE_ERROR.title}
        errorMessage={MESSAGING_USAGE_ERROR.message}
        formatTotal={formatMessagingCountTotal}
        formatValue={formatMessagingCountValue}
        onRetry={handleRetryAll}
        docsHref={MESSAGING_DOCS_HREF}
      />

      <UsageTimeSeriesChartCard
        title="Topics"
        description={MESSAGING_TOPICS_DESCRIPTION}
        unitLabel="topics"
        chartGradientId="usage-messaging-topics-gradient"
        total={getMessagingTopicsDisplayTotal(topicsPoints)}
        changePercent={topicsQuery.data?.changePercent ?? 0}
        chartPoints={topicsPoints}
        isLoading={shouldShowUsageChartSkeleton(
          topicsQuery.isError,
          topicsQuery.isLoading,
          topicsQuery.isPlaceholderData,
        )}
        isError={topicsQuery.isError}
        queryError={topicsQuery.error}
        errorTitle={MESSAGING_USAGE_ERROR.title}
        errorMessage={MESSAGING_USAGE_ERROR.message}
        formatTotal={formatMessagingCountTotal}
        formatValue={formatMessagingCountValue}
        onRetry={handleRetryAll}
        docsHref={MESSAGING_DOCS_HREF}
      />

      <UsageTimeSeriesChartCard
        title="SMS messages"
        description={MESSAGING_SMS_DESCRIPTION}
        unitLabel="messages"
        chartGradientId="usage-messaging-sms-gradient"
        total={sumUsageChartPoints(smsPoints)}
        changePercent={smsQuery.data?.changePercent ?? 0}
        chartPoints={smsPoints}
        isLoading={shouldShowUsageChartSkeleton(
          smsQuery.isError,
          smsQuery.isLoading,
          smsQuery.isPlaceholderData,
        )}
        isError={smsQuery.isError}
        queryError={smsQuery.error}
        errorTitle={MESSAGING_USAGE_ERROR.title}
        errorMessage={MESSAGING_USAGE_ERROR.message}
        formatTotal={formatMessagingCountTotal}
        formatValue={formatMessagingCountValue}
        onRetry={handleRetryAll}
        docsHref={MESSAGING_DOCS_HREF}
      />
    </div>
  )
}
