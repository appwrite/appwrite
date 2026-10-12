import { useQuery, queryOptions } from '@tanstack/react-query'
import type { QueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import {
  fetchAccountAgentUsageOverview,
  type AccountAgentUsageOverview,
} from '@/lib/usage/agent-usage'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import {
  getStableUsageChartDateRange,
  resolveUsageDateBounds,
} from '@/lib/usage/usage-date-range'
import { DEFAULT_STALE_TIME } from './constants'

function agentUsageRangeKey(dateRange: DateRange | undefined): string {
  if (!dateRange?.from || !dateRange?.to) {
    const stable = getStableUsageChartDateRange()
    const { from, to } = resolveUsageDateBounds(stable)
    return `${from.toISOString()}|${to.toISOString()}`
  }
  const { from, to } = resolveUsageDateBounds(dateRange)
  return `${from.toISOString()}|${to.toISOString()}`
}

export function accountAgentUsageQueryOptions(
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const rangeKey = agentUsageRangeKey(dateRange)

  return queryOptions({
    queryKey: ['usage-events', 'agent', 'account', 'overview', rangeKey, interval],
    queryFn: () => fetchAccountAgentUsageOverview(dateRange, interval),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    meta: {
      skipInitialLoader: true,
    },
  })
}

export function useAccountAgentUsage(
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  enabled = true,
) {
  return useQuery({
    ...accountAgentUsageQueryOptions(dateRange, interval),
    enabled,
  })
}

/** Refetch account-scoped agent usage charts. */
export function refetchAccountAgentUsageQueries(queryClient: QueryClient) {
  return queryClient.refetchQueries({
    predicate: (query) =>
      Array.isArray(query.queryKey) &&
      query.queryKey[0] === 'usage-events' &&
      query.queryKey[1] === 'agent' &&
      query.queryKey[2] === 'account',
  })
}

export type { AccountAgentUsageOverview }
