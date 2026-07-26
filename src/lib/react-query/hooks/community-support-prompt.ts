import { useCallback, useEffect, useMemo } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
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

function patchAccountPrefsCache(
  queryClient: ReturnType<typeof useQueryClient>,
  next: CommunitySupportPrefs,
) {
  queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
    { queryKey: ['account', 'console'] },
    (current) =>
      current
        ? {
            ...current,
            prefs: mergeCommunitySupportPrefsIntoPrefs(
              (current.prefs ?? {}) as UserPrefs,
              next,
            ),
          }
        : current,
  )
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

  /** Record today's visit as a unique active day when needed. */
  useEffect(() => {
    if (!account || !trackActiveDay) return
    const latest = readLatestState()
    const next = withRecordedActiveDay(latest)
    if (
      next.uniqueDayCount === latest.uniqueDayCount &&
      next.lastActiveDay === latest.lastActiveDay
    ) {
      return
    }
    persist(next)
  }, [account, persist, readLatestState, state, trackActiveDay])

  const shouldShow = shouldShowCommunitySupportPrompt(state)

  const recordShown = useCallback(() => {
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
