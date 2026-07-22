import { useMemo, useSyncExternalStore } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import { isUsageHistoryLimitExceededError } from '@/lib/usage/usage-history-errors'
import { getUsageChartQueryRangeKeyPart } from '@/lib/usage/usage-date-range'
import {
  hasFiniteUsageLogRetention,
  isUsageDateRangeBeyondRetention,
} from '@/lib/usage/usage-log-retention'
import type { Models } from '@appwrite.io/console'

function isProjectUsageQueryKey(
  queryKey: readonly unknown[],
  projectId: string,
): boolean {
  const root = queryKey[0]
  if (
    root !== 'usage-events' &&
    root !== 'usage-gauges' &&
    root !== 'firewall-impact'
  ) {
    return false
  }

  const projectIndex = queryKey.indexOf('project')
  if (projectIndex === -1) return false

  return queryKey[projectIndex + 1] === projectId
}

export function useUsageHistoryLimitAlertState({
  projectId,
  dateRange,
  dateRangePresetId,
  retentionHours,
  organizationPlan,
}: {
  projectId: string | undefined
  dateRange: DateRange | undefined
  dateRangePresetId?: string | null
  retentionHours: number
  organizationPlan?: Models.BillingPlan | null
}) {
  const queryClient = useQueryClient()
  const currentRangeKeyPart = useMemo(
    () => getUsageChartQueryRangeKeyPart(dateRange, dateRangePresetId),
    [dateRange, dateRangePresetId],
  )

  const hasUsageHistoryLimitError = useSyncExternalStore(
    (onStoreChange) =>
      queryClient.getQueryCache().subscribe((event) => {
        if (event.type === 'updated' || event.type === 'added') {
          onStoreChange()
        }
      }),
    () => {
      if (!projectId) return false

      const impactFrom = dateRange?.from?.toISOString() ?? ''
      const impactTo = dateRange?.to?.toISOString() ?? ''

      return queryClient
        .getQueryCache()
        .getAll()
        .some((query) => {
          if (query.state.status !== 'error') return false
          if (!isProjectUsageQueryKey(query.queryKey, projectId)) return false
          if (!isUsageHistoryLimitExceededError(query.state.error)) return false

          if (query.queryKey[0] === 'firewall-impact') {
            return (
              query.queryKey.includes(impactFrom) &&
              query.queryKey.includes(impactTo)
            )
          }

          return query.queryKey.includes(currentRangeKeyPart)
        })
    },
    () => false,
  )

  const rangeBeyondRetention =
    !!organizationPlan &&
    hasFiniteUsageLogRetention(organizationPlan) &&
    isUsageDateRangeBeyondRetention(
      dateRange,
      retentionHours,
      dateRangePresetId,
    )

  return {
    showAlert: hasUsageHistoryLimitError || rangeBeyondRetention,
    triggeredByServerError: hasUsageHistoryLimitError,
    triggeredByDateRange: rangeBeyondRetention,
  }
}
