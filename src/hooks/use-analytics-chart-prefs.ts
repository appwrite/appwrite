import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  getConsoleAccountFromCache,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  mergeAnalyticsChartFiltersIntoPrefs,
  type AnalyticsChartIntervalPref,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import {
  resolveAnalyticsChartSelection,
  serializeAnalyticsChartSelection,
  type AnalyticsChartSelection,
} from '@/lib/analytics/chart-prefs'
import type { SerializedUsageChartDateRange } from '@/lib/usage/usage-date-range'

const PERSIST_DEBOUNCE_MS = 400

type PendingWrite = {
  range: SerializedUsageChartDateRange
  interval: AnalyticsChartIntervalPref
  key: string
}

/**
 * Analytics chart date range + interval in account prefs
 * (`console.analytics.dateRange` / `console.analytics.interval`): one setting
 * for every analytics property, separate from the usage chart range.
 */
export function useAnalyticsChartPrefs() {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const prefs = (account as Models.User | undefined)?.prefs as UserPrefs | undefined

  // Read once per mount: later pref updates are this page's own writes.
  const [initial] = useState(() => resolveAnalyticsChartSelection(prefs))

  const lastWrittenRef = useRef(
    JSON.stringify([serializeAnalyticsChartSelection(initial), initial.interval]),
  )
  const pendingRef = useRef<PendingWrite | null>(null)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const mutation = useMutation({
    mutationFn: async (write: PendingWrite) => {
      const current = (getConsoleAccountFromCache(queryClient) ?? account) as
        | Models.User
        | undefined
      if (!current) throw new Error('Account data not available')
      return await updateAccountPrefs(
        mergeAnalyticsChartFiltersIntoPrefs(
          (current.prefs ?? {}) as UserPrefs,
          write.range,
          write.interval,
        ),
        'analytics-chart',
      )
    },
    // Optimistic, like the usage filters: a quick revisit reads the new value.
    onMutate: (write) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (cached) =>
          cached
            ? {
                ...cached,
                prefs: mergeAnalyticsChartFiltersIntoPrefs(
                  (cached.prefs ?? {}) as UserPrefs,
                  write.range,
                  write.interval,
                ),
              }
            : cached,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, { apiResult: updatedAccount })
    },
  })
  const mutateRef = useRef(mutation.mutate)
  mutateRef.current = mutation.mutate

  const flush = useCallback(() => {
    if (timerRef.current !== null) {
      clearTimeout(timerRef.current)
      timerRef.current = null
    }
    const write = pendingRef.current
    if (!write) return
    pendingRef.current = null
    lastWrittenRef.current = write.key
    mutateRef.current(write)
  }, [])

  /** Save the selection; no-op when it matches what's already stored. */
  const save = useCallback(
    (selection: AnalyticsChartSelection) => {
      if (!account) return
      const range = serializeAnalyticsChartSelection(selection)
      const key = JSON.stringify([range, selection.interval])
      if (key === (pendingRef.current?.key ?? lastWrittenRef.current)) return
      pendingRef.current = { range, interval: selection.interval, key }
      if (timerRef.current !== null) clearTimeout(timerRef.current)
      timerRef.current = setTimeout(flush, PERSIST_DEBOUNCE_MS)
    },
    [account, flush],
  )

  // Don't drop a pending write when leaving the page.
  useEffect(() => {
    window.addEventListener('pagehide', flush)
    return () => {
      window.removeEventListener('pagehide', flush)
      flush()
    }
  }, [flush])

  return { initial, save }
}
