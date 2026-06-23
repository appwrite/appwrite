import { useCallback, useMemo, useRef } from 'react'
import type { DateRange } from 'react-day-picker'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  resolveUsageChartIntervalForRange,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import {
  resolveUsageChartFiltersFromPrefs,
  serializeUsageChartFilters,
} from '@/lib/usage/usage-chart-filters'
import {
  getStableUsageChartDateRange,
  resetStableUsageChartDateRange,
} from '@/lib/usage/usage-date-range'
import {
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  mergeUsageChartFiltersIntoPrefs,
  parseUsageChartDateRangeFromPrefs,
  parseUsageChartIntervalFromPrefs,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

export function useUsageChartFilters() {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const accountId = (account as Models.User | undefined)?.$id
  const accountPrefs = (account as Models.User | undefined)?.prefs as
    | UserPrefs
    | undefined

  const previousAccountIdRef = useRef(accountId)
  if (previousAccountIdRef.current !== accountId) {
    resetStableUsageChartDateRange()
    previousAccountIdRef.current = accountId
  }

  const usageFiltersPrefsKey = useMemo(() => {
    const serialized = parseUsageChartDateRangeFromPrefs(accountPrefs)
    const interval = parseUsageChartIntervalFromPrefs(accountPrefs)
    if (serialized) {
      return `${serialized.from}|${serialized.to}|${interval ?? ''}`
    }
    return `default:${accountId ?? 'anonymous'}`
  }, [accountPrefs, accountId])

  const { dateRange, chartInterval } = useMemo(
    () => resolveUsageChartFiltersFromPrefs(accountPrefs),
    [usageFiltersPrefsKey],
  )

  const updateMutation = useMutation({
    mutationFn: async (next: {
      dateRange: DateRange
      chartInterval: UsageChartInterval
    }) => {
      const currentAccount = account as Models.User | undefined
      if (!currentAccount) {
        throw new Error('Account data not available')
      }
      const { serializedDateRange, chartInterval: interval } =
        serializeUsageChartFilters({
          dateRange: next.dateRange,
          chartInterval: next.chartInterval,
        })
      return await updateAccountPrefs(
        mergeUsageChartFiltersIntoPrefs(
          (currentAccount.prefs ?? {}) as UserPrefs,
          serializedDateRange,
          interval,
        ),
      )
    },
    onMutate: async (next) => {
      const { serializedDateRange, chartInterval: interval } =
        serializeUsageChartFilters({
          dateRange: next.dateRange,
          chartInterval: next.chartInterval,
        })
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeUsageChartFiltersIntoPrefs(
                  (current.prefs ?? {}) as UserPrefs,
                  serializedDateRange,
                  interval,
                ),
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const setDateRange = useCallback(
    (nextDateRange: DateRange | undefined) => {
      if (!account) return

      const resolvedDateRange = nextDateRange?.from
        ? {
            from: nextDateRange.from,
            to: nextDateRange.to ?? nextDateRange.from,
          }
        : getStableUsageChartDateRange()

      const nextInterval = resolveUsageChartIntervalForRange(
        chartInterval,
        resolvedDateRange,
      )

      if (
        resolvedDateRange.from?.getTime() === dateRange.from?.getTime() &&
        resolvedDateRange.to?.getTime() === dateRange.to?.getTime() &&
        nextInterval === chartInterval
      ) {
        return
      }

      updateMutation.mutate({
        dateRange: resolvedDateRange,
        chartInterval: nextInterval,
      })
    },
    [account, chartInterval, dateRange, updateMutation],
  )

  const setChartInterval = useCallback(
    (interval: UsageChartInterval) => {
      if (!account || interval === chartInterval) return
      updateMutation.mutate({ dateRange, chartInterval: interval })
    },
    [account, chartInterval, dateRange, updateMutation],
  )

  return {
    dateRange,
    chartInterval,
    setDateRange,
    setChartInterval,
  }
}
