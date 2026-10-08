import { useCallback, useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  getConsoleAccountFromCache,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  mergeAnalyticsCardTabIntoPrefs,
  parseAnalyticsCardTabsFromPrefs,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

const PERSIST_DEBOUNCE_MS = 400

/**
 * The selected tab of an analytics card, remembered in account prefs
 * (`console.analytics.cardTabs`): one setting per card across every project
 * and property. Falls back to `fallbackId` when nothing (or a tab that no
 * longer exists) is stored.
 */
export function useAnalyticsCardTab(
  cardId: string | undefined,
  tabIds: readonly string[],
  fallbackId: string,
): [string, (tabId: string) => void] {
  const { account } = useAuth()
  const queryClient = useQueryClient()

  const [tab, setTabState] = useState(() => {
    if (!cardId) return fallbackId
    const stored = parseAnalyticsCardTabsFromPrefs(
      (account as Models.User | undefined)?.prefs as UserPrefs | undefined,
    )[cardId]
    return stored && tabIds.includes(stored) ? stored : fallbackId
  })

  const pendingRef = useRef<string | null>(null)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const flush = useCallback(() => {
    if (timerRef.current !== null) {
      clearTimeout(timerRef.current)
      timerRef.current = null
    }
    const next = pendingRef.current
    pendingRef.current = null
    if (!cardId || next === null) return

    const current = getConsoleAccountFromCache(queryClient) as
      | Models.User
      | undefined
    const prefs = (current?.prefs ?? {}) as UserPrefs
    if (parseAnalyticsCardTabsFromPrefs(prefs)[cardId] === next) return

    const merged = mergeAnalyticsCardTabIntoPrefs(prefs, cardId, next)
    // Optimistic, so another card writing right after merges on top of this.
    queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
      { queryKey: ['account', 'console'] },
      (cached) =>
        cached
          ? {
              ...cached,
              prefs: mergeAnalyticsCardTabIntoPrefs(
                (cached.prefs ?? {}) as UserPrefs,
                cardId,
                next,
              ),
            }
          : cached,
    )
    void updateAccountPrefs(merged, 'analytics-card-tab')
      .then((updated) => {
        if (updated) {
          syncConsoleAccountAfterMutation(queryClient, { apiResult: updated })
        }
      })
      .catch(() => {
        // A lost tab preference isn't worth surfacing.
      })
  }, [cardId, queryClient])

  useEffect(() => {
    window.addEventListener('pagehide', flush)
    return () => {
      window.removeEventListener('pagehide', flush)
      flush()
    }
  }, [flush])

  const setTab = useCallback(
    (next: string) => {
      setTabState(next)
      if (!cardId || !account) return
      pendingRef.current = next
      if (timerRef.current !== null) clearTimeout(timerRef.current)
      timerRef.current = setTimeout(flush, PERSIST_DEBOUNCE_MS)
    },
    [account, cardId, flush],
  )

  return [tab, setTab]
}
