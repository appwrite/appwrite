'use client'

import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import { BarChart3 } from 'lucide-react'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { UsageChartIntervalToggle } from '@/components/pages/projects/$projectId/overview/UsageChartIntervalToggle'
import { UsageTimeSeriesChartCard } from '@/components/pages/projects/$projectId/usage/_components/UsageTimeSeriesChartCard'
import { AgentUsageMultiSeriesChartCard } from '@/components/pages/agent/settings/_components/AgentUsageMultiSeriesChartCard'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useT } from '@/lib/i18n/translate'
import {
  AGENT_ACTIVITY_DESCRIPTION,
  AGENT_ACTIVITY_SERIES,
  AGENT_AUTOMATIONS_DESCRIPTION,
  AGENT_DOCS_HREF,
  AGENT_TOKENS_BREAKDOWN_DESCRIPTION,
  AGENT_TOKENS_BREAKDOWN_SERIES,
  formatAgentCountTotal,
  formatAgentCountValue,
  sumUsageChartPoints,
} from '@/lib/usage/agent-usage'
import {
  refetchAccountAgentUsageQueries,
  useAccountAgentUsage,
} from '@/lib/react-query/hooks'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  resolveUsageChartIntervalForRange,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import { getStableUsageChartDateRange } from '@/lib/usage/usage-date-range'
import { shouldShowUsageChartSkeleton } from '@/lib/usage/usage-chart-loading'

const AGENT_USAGE_ERROR = {
  title: "Couldn't load agent usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

export function Usage() {
  const t = useT()
  const { isAuthenticated } = useAuth()
  const queryClient = useQueryClient()

  const [dateRange, setDateRange] = useState<DateRange>(() =>
    getStableUsageChartDateRange(),
  )
  const [chartInterval, setChartInterval] = useState<UsageChartInterval>(
    DEFAULT_USAGE_CHART_INTERVAL,
  )

  const resolvedInterval = resolveUsageChartIntervalForRange(
    chartInterval,
    dateRange,
  )

  const usageQuery = useAccountAgentUsage(
    dateRange,
    resolvedInterval,
    isAuthenticated,
  )

  const handleDateRangeChange = (next: DateRange | undefined) => {
    if (!next?.from || !next?.to) return
    setDateRange(next)
    setChartInterval((current) =>
      resolveUsageChartIntervalForRange(current, next),
    )
  }

  const handleIntervalChange = (next: UsageChartInterval) => {
    setChartInterval(resolveUsageChartIntervalForRange(next, dateRange))
  }

  const handleRetry = () => {
    void refetchAccountAgentUsageQueries(queryClient)
  }

  const overview = usageQuery.isError ? undefined : usageQuery.data
  const showSkeleton = shouldShowUsageChartSkeleton(
    usageQuery.isError,
    usageQuery.isLoading,
    usageQuery.isPlaceholderData,
  )

  const automationsPoints = overview?.automations.chartPoints ?? []
  const tokensBreakdown = overview?.tokensBreakdown
  const activity = overview?.activity

  return (
    <div className="space-y-6" data-settings-card="Usage">
      <div className="w-full rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="flex flex-col gap-4 px-6 py-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <BarChart3
                className="h-4 w-4 shrink-0 text-muted-foreground"
                aria-hidden
              />
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Usage')}
              </h3>
            </div>
            <p className="mt-2 text-[13px] text-muted-foreground max-w-xl">
              {t(
                'Your personal Agent activity: tokens, messages, conversations, tool calls, and automations.',
              )}
            </p>
          </div>
          <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center sm:justify-end">
            <UsageChartIntervalToggle
              value={resolvedInterval}
              onValueChange={(value) =>
                handleIntervalChange(value as UsageChartInterval)
              }
              dateRange={dateRange}
              className="h-9 w-full sm:w-fit"
            />
            <DateRangePicker
              dateRange={dateRange}
              onDateRangeChange={handleDateRangeChange}
              className="h-9 w-full min-w-0 sm:w-auto sm:min-w-[180px]"
              popoverContentAlign="end"
            />
          </div>
        </div>
      </div>

      <AgentUsageMultiSeriesChartCard
        title="Tokens"
        description={AGENT_TOKENS_BREAKDOWN_DESCRIPTION}
        unitLabel="tokens"
        total={sumUsageChartPoints(tokensBreakdown?.chartPoints ?? [])}
        changePercent={tokensBreakdown?.changePercent ?? 0}
        multiSeriesPoints={tokensBreakdown?.multiSeriesPoints ?? []}
        series={tokensBreakdown?.series ?? AGENT_TOKENS_BREAKDOWN_SERIES}
        isLoading={showSkeleton}
        isError={usageQuery.isError}
        queryError={usageQuery.error}
        errorTitle={AGENT_USAGE_ERROR.title}
        errorMessage={AGENT_USAGE_ERROR.message}
        formatTotal={formatAgentCountTotal}
        formatValue={formatAgentCountValue}
        onRetry={handleRetry}
        docsHref={AGENT_DOCS_HREF}
        dateRange={dateRange}
        chartInterval={resolvedInterval}
        onDateRangeChange={handleDateRangeChange}
        stackId="agent-tokens"
      />

      <AgentUsageMultiSeriesChartCard
        title="Activity"
        description={AGENT_ACTIVITY_DESCRIPTION}
        unitLabel="events"
        total={sumUsageChartPoints(activity?.chartPoints ?? [])}
        changePercent={activity?.changePercent ?? 0}
        multiSeriesPoints={activity?.multiSeriesPoints ?? []}
        series={activity?.series ?? AGENT_ACTIVITY_SERIES}
        isLoading={showSkeleton}
        isError={usageQuery.isError}
        queryError={usageQuery.error}
        errorTitle={AGENT_USAGE_ERROR.title}
        errorMessage={AGENT_USAGE_ERROR.message}
        formatTotal={formatAgentCountTotal}
        formatValue={formatAgentCountValue}
        onRetry={handleRetry}
        docsHref={AGENT_DOCS_HREF}
        dateRange={dateRange}
        chartInterval={resolvedInterval}
        onDateRangeChange={handleDateRangeChange}
        stackId="agent-activity"
      />

      <UsageTimeSeriesChartCard
        title="Automations"
        description={AGENT_AUTOMATIONS_DESCRIPTION}
        unitLabel="automations"
        chartGradientId="usage-agent-automations-gradient"
        total={sumUsageChartPoints(automationsPoints)}
        changePercent={overview?.automations.changePercent ?? 0}
        chartPoints={automationsPoints}
        isLoading={showSkeleton}
        isError={usageQuery.isError}
        queryError={usageQuery.error}
        errorTitle={AGENT_USAGE_ERROR.title}
        errorMessage={AGENT_USAGE_ERROR.message}
        formatTotal={formatAgentCountTotal}
        formatValue={formatAgentCountValue}
        onRetry={handleRetry}
        docsHref={AGENT_DOCS_HREF}
        dateRange={dateRange}
        chartInterval={resolvedInterval}
        onDateRangeChange={handleDateRangeChange}
      />
    </div>
  )
}
