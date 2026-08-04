import { useCallback, useEffect, useMemo, useRef } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import {
  commitConsoleAccountToCaches,
  getConsoleAccountFromCache,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
  type ConsoleAccountCache,
} from '@/lib/react-query/hooks/auth'
import {
  mergeCommunitySupportPrefsIntoPrefs,
  parseCommunitySupportPrefs,
  type CommunitySupportPrefs,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import {
  getLocalDayKey,
  shouldShowCommunitySupportPrompt,
  withRecordedActiveDay,
  withRecordedShow,
  withSkippedPrompt,
  withTakenAction,
  type CommunitySupportActionId,
  type CommunitySupportPromptState,
} from '@/lib/community/support-prompt'

function toPromptState(prefs: CommunitySupportPrefs): CommunitySupportPromptState {
  return {
    uniqueDayCount: prefs.uniqueDayCount,
    lastActiveDay: prefs.lastActiveDay,
    shownCount: prefs.shownCount,
    lastShownAt: prefs.lastShownAt,
    actionTakenAt: prefs.actionTakenAt,
    actionId: (prefs.actionId as CommunitySupportActionId | null) ?? null,
  }
}

function toPrefs(state: CommunitySupportPromptState): CommunitySupportPrefs {
  return {
    uniqueDayCount: state.uniqueDayCount,
    lastActiveDay: state.lastActiveDay,
    shownCount: state.shownCount,
    lastShownAt: state.lastShownAt,
    actionTakenAt: state.actionTakenAt,
    actionId: state.actionId,
  }
}

function isAccountUser(
  value: ConsoleAccountCache | undefined,
): value is Models.User {
  return !!value && typeof value === 'object' && '$id' in value
}

/**
 * Patch RQ + module singleton together. `updateAccountPrefs` diffs against the
 * singleton; optimistic RQ-only updates made every write look new and stormed
 * `[account prefs] update` / React #185.
 */
function patchAccountPrefsCache(
  queryClient: ReturnType<typeof useQueryClient>,
  next: CommunitySupportPrefs,
) {
  const current = getConsoleAccountFromCache(queryClient)
  if (!isAccountUser(current)) {
    queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
      { queryKey: ['account', 'console'] },
      (existing) =>
        existing
          ? {
              ...existing,
              prefs: mergeCommunitySupportPrefsIntoPrefs(
                (existing.prefs ?? {}) as UserPrefs,
                next,
              ),
            }
          : existing,
    )
    return
  }

  commitConsoleAccountToCaches(queryClient, {
    ...current,
    prefs: mergeCommunitySupportPrefsIntoPrefs(
      (current.prefs ?? {}) as UserPrefs,
      next,
    ),
  } as Models.User)
}

/**
 * Tracks unique console usage days and community-support wizard state in
 * account prefs (`console.communitySupport`).
 */
export function useCommunitySupportPrompt(
  account: ConsoleAccountCache | undefined,
  options?: { trackActiveDay?: boolean },
) {
  const queryClient = useQueryClient()
  const trackActiveDay = options?.trackActiveDay ?? true
  const accountId = isAccountUser(account) ? account.$id : undefined

  /** One active-day write per account+calendar day (prevents re-entry storms). */
  const recordedDayGuardRef = useRef<string | null>(null)
  /** One impression stamp per open cycle. */
  const recordedShowGuardRef = useRef(false)

  const state = useMemo(
    () =>
      toPromptState(
        parseCommunitySupportPrefs(account?.prefs as UserPrefs | undefined),
      ),
    [account?.prefs],
  )

  const updateMutation = useMutation({
    mutationFn: async (next: CommunitySupportPrefs) => {
      const currentAccount =
        getConsoleAccountFromCache(queryClient) ?? account
      if (!currentAccount) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeCommunitySupportPrefsIntoPrefs(
          (currentAccount.prefs ?? {}) as UserPrefs,
          next,
        ),
        'community-support-prompt',
      )
    },
    onMutate: async (next) => {
      patchAccountPrefsCache(queryClient, next)
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const readLatestState = useCallback((): CommunitySupportPromptState => {
    const cached = getConsoleAccountFromCache(queryClient)
    return toPromptState(
      parseCommunitySupportPrefs(
        (cached?.prefs ?? account?.prefs) as UserPrefs | undefined,
      ),
    )
  }, [account?.prefs, queryClient])

  const persist = useCallback(
    (next: CommunitySupportPromptState) => {
      if (!account) return
      updateMutation.mutate(toPrefs(next))
    },
    [account, updateMutation],
  )

  // Reset guards when the signed-in account changes (incl. impersonation).
  useEffect(() => {
    recordedDayGuardRef.current = null
    recordedShowGuardRef.current = false
  }, [accountId])

  /** Record today's visit as a unique active day when needed. */
  useEffect(() => {
    if (!account || !trackActiveDay) return

    const dayKey = getLocalDayKey()
    const guardKey = `${accountId ?? 'unknown'}:${dayKey}`
    if (recordedDayGuardRef.current === guardKey) return

    const latest = readLatestState()
    if (latest.lastActiveDay === dayKey) {
      recordedDayGuardRef.current = guardKey
      return
    }

    if (updateMutation.isPending) return

    // Set before mutate so account-identity churn from onMutate cannot re-enter.
    recordedDayGuardRef.current = guardKey
    persist(withRecordedActiveDay(latest, dayKey))
  }, [
    account,
    accountId,
    persist,
    readLatestState,
    trackActiveDay,
    updateMutation.isPending,
  ])

  const shouldShow = shouldShowCommunitySupportPrompt(state)

  useEffect(() => {
    if (!shouldShow) {
      recordedShowGuardRef.current = false
    }
  }, [shouldShow])

  const recordShown = useCallback(() => {
    if (recordedShowGuardRef.current) return
    recordedShowGuardRef.current = true
    persist(withRecordedShow(readLatestState()))
  }, [persist, readLatestState])

  const skip = useCallback(() => {
    persist(withSkippedPrompt(readLatestState()))
  }, [persist, readLatestState])

  const takeAction = useCallback(
    (actionId: CommunitySupportActionId) => {
      persist(withTakenAction(readLatestState(), actionId))
    },
    [persist, readLatestState],
  )

  return {
    state,
    shouldShow,
    recordShown,
    skip,
    takeAction,
    todayKey: getLocalDayKey(),
  }
}
