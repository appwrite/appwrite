import { useCallback, useMemo, useRef, useState } from 'react'
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
  isRollingUsageDateRangePresetId,
  resetStableUsageChartDateRange,
} from '@/lib/usage/usage-date-range'
import { getUsageDateRangePresetByValue } from '@/lib/usage/usage-date-range-presets'
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
  const [rollingRangeNonce, setRollingRangeNonce] = useState(0)

  const previousAccountIdRef = useRef(accountId)
  if (previousAccountIdRef.current !== accountId) {
    resetStableUsageChartDateRange()
    previousAccountIdRef.current = accountId
  }

  const usageFiltersPrefsKey = useMemo(() => {
    const serialized = parseUsageChartDateRangeFromPrefs(accountPrefs)
    const interval = parseUsageChartIntervalFromPrefs(accountPrefs)
    if (serialized) {
      return `${serialized.preset ?? ''}|${serialized.from ?? ''}|${serialized.to ?? ''}|${interval ?? ''}`
    }
    return `default:${accountId ?? 'anonymous'}`
  }, [accountPrefs, accountId])

  const dateRangePresetId = useMemo(() => {
    const serialized = parseUsageChartDateRangeFromPrefs(accountPrefs)
    return serialized?.preset ?? null
  }, [usageFiltersPrefsKey, accountPrefs])

  const { dateRange, chartInterval } = useMemo(() => {
    const filters = resolveUsageChartFiltersFromPrefs(accountPrefs)

    if (dateRangePresetId) {
      const preset = getUsageDateRangePresetByValue(dateRangePresetId)
      if (preset) {
        return {
          dateRange: preset.getRange(),
          chartInterval: filters.chartInterval,
        }
      }
    }

    return filters
  }, [usageFiltersPrefsKey, dateRangePresetId, rollingRangeNonce, accountPrefs])

  const refreshRollingDateRange = useCallback(() => {
    if (dateRangePresetId && isRollingUsageDateRangePresetId(dateRangePresetId)) {
      setRollingRangeNonce((nonce) => nonce + 1)
    }
  }, [dateRangePresetId])

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
    dateRangePresetId,
    setDateRange,
    setChartInterval,
    refreshRollingDateRange,
  }
}
