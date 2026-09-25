import { useCallback, useMemo, useRef, useState } from 'react'
import type { DateRange } from 'react-day-picker'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  clampDateRangeToRetentionFloor,
  hasFiniteLogRetentionHours,
} from '@/lib/date-range-retention'
import {
  resolveUsageChartInterval,
  type UsageChartInterval,
  type UsageChartIntervalPlan,
} from '@/lib/usage/chart-interval'
import {
  resolveUsageChartFiltersFromPrefs,
  serializeUsageChartFilters,
} from '@/lib/usage/usage-chart-filters'
import {
  getStableUsageChartDateRange,
  normalizeUsageDateRangeSelection,
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

export function useUsageChartFilters(
  plan?: UsageChartIntervalPlan,
  logRetentionHours?: number,
) {
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

  const planIntervalsKey = plan?.usageLogsIntervals?.join(',') ?? ''

  const dateRangePresetId = useMemo(() => {
    const serialized = parseUsageChartDateRangeFromPrefs(accountPrefs)
    if (serialized?.preset) return serialized.preset
    // Default overview range is rolling last 24 hours when nothing is saved.
    if (!serialized) return '24h'
    return null
  }, [usageFiltersPrefsKey, accountPrefs])

  const { dateRange, chartInterval } = useMemo(() => {
    const filters = resolveUsageChartFiltersFromPrefs(accountPrefs, plan)

    let resolvedDateRange = filters.dateRange
    let resolvedChartInterval = filters.chartInterval

    if (dateRangePresetId) {
      const preset = getUsageDateRangePresetByValue(dateRangePresetId)
      if (preset) {
        resolvedDateRange = preset.getRange()
        resolvedChartInterval = resolveUsageChartInterval(
          filters.chartInterval,
          resolvedDateRange,
          plan,
        )
      }
    }

    const clampedDateRange =
      logRetentionHours != null &&
      hasFiniteLogRetentionHours(logRetentionHours)
        ? clampDateRangeToRetentionFloor(
            resolvedDateRange,
            logRetentionHours,
          ) ?? resolvedDateRange
        : resolvedDateRange

    return {
      dateRange: clampedDateRange,
      chartInterval: resolvedChartInterval,
    }
  }, [
    usageFiltersPrefsKey,
    dateRangePresetId,
    rollingRangeNonce,
    accountPrefs,
    plan,
    planIntervalsKey,
    logRetentionHours,
  ])

  const refreshRollingDateRange = useCallback(() => {
    setRollingRangeNonce((nonce) => nonce + 1)
  }, [])

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

      const normalizedSelection = normalizeUsageDateRangeSelection(nextDateRange)
      let nextResolvedDateRange = normalizedSelection?.from
        ? {
            from: normalizedSelection.from,
            to: normalizedSelection.to ?? normalizedSelection.from,
          }
        : getStableUsageChartDateRange()

      if (
        logRetentionHours != null &&
        hasFiniteLogRetentionHours(logRetentionHours)
      ) {
        nextResolvedDateRange =
          clampDateRangeToRetentionFloor(
            nextResolvedDateRange,
            logRetentionHours,
          ) ?? nextResolvedDateRange
      }

      const nextInterval = resolveUsageChartInterval(
        chartInterval,
        nextResolvedDateRange,
        plan,
      )

      if (
        nextResolvedDateRange.from?.getTime() === dateRange.from?.getTime() &&
        nextResolvedDateRange.to?.getTime() === dateRange.to?.getTime() &&
        nextInterval === chartInterval
      ) {
        return
      }

      updateMutation.mutate({
        dateRange: nextResolvedDateRange,
        chartInterval: nextInterval,
      })
    },
    [account, chartInterval, dateRange, logRetentionHours, plan, updateMutation],
  )

  const setChartInterval = useCallback(
    (interval: UsageChartInterval) => {
      if (!account || interval === chartInterval) return
      const resolved = resolveUsageChartInterval(interval, dateRange, plan)
      if (resolved === chartInterval) return
      updateMutation.mutate({ dateRange, chartInterval: resolved })
    },
    [account, chartInterval, dateRange, plan, updateMutation],
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
