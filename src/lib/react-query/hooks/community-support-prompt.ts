import { useCallback, useEffect, useMemo, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
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
  isCommunitySupportStateAhead,
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
  const accountRef = useRef(account)
  accountRef.current = account

  /** One active-day write per account+calendar day (prevents re-entry storms). */
  const recordedDayGuardRef = useRef<string | null>(null)
  /** One impression stamp per open cycle (reset only on account change). */
  const recordedShowGuardRef = useRef(false)
  /** Serialize prefs writes so active-day and show/skip cannot clobber each other. */
  const writeChainRef = useRef(Promise.resolve())

  const state = useMemo(
    () =>
      toPromptState(
        parseCommunitySupportPrefs(account?.prefs as UserPrefs | undefined),
      ),
    [account?.prefs],
  )

  const readLatestState = useCallback((): CommunitySupportPromptState => {
    const cached = getConsoleAccountFromCache(queryClient)
    return toPromptState(
      parseCommunitySupportPrefs(
        (cached?.prefs ?? accountRef.current?.prefs) as UserPrefs | undefined,
      ),
    )
  }, [queryClient])

  const persist = useCallback(
    (next: CommunitySupportPromptState) => {
      if (!accountRef.current) return

      const nextPrefs = toPrefs(next)
      // Optimistic update immediately so shouldShow / UI can react.
      patchAccountPrefsCache(queryClient, nextPrefs)

      writeChainRef.current = writeChainRef.current
        .catch(() => undefined)
        .then(async () => {
          const currentAccount =
            getConsoleAccountFromCache(queryClient) ?? accountRef.current
          if (!currentAccount) return

          // Always write the latest community-support slice from cache so a
          // queued active-day write cannot overwrite a later show/skip.
          const latestCs = parseCommunitySupportPrefs(
            (getConsoleAccountFromCache(queryClient)?.prefs ??
              currentAccount.prefs) as UserPrefs,
          )
          const basePrefs = (getConsoleAccountFromCache(queryClient)?.prefs ??
            currentAccount.prefs ??
            {}) as UserPrefs
          const updatedAccount = await updateAccountPrefs(
            mergeCommunitySupportPrefsIntoPrefs(basePrefs, latestCs),
            'community-support-prompt',
          )
          if (!updatedAccount) return

          const localBeforeSync = parseCommunitySupportPrefs(
            getConsoleAccountFromCache(queryClient)?.prefs as
              | UserPrefs
              | undefined,
          )
          syncConsoleAccountAfterMutation(queryClient, {
            apiResult: updatedAccount,
          })
          const remoteAfterSync = parseCommunitySupportPrefs(
            updatedAccount.prefs as UserPrefs | undefined,
          )
          if (
            isCommunitySupportStateAhead(
              toPromptState(localBeforeSync),
              toPromptState(remoteAfterSync),
            )
          ) {
            patchAccountPrefsCache(queryClient, localBeforeSync)
          }
        })
    },
    [queryClient],
  )

  // Reset guards when the signed-in account changes (incl. impersonation).
  useEffect(() => {
    recordedDayGuardRef.current = null
    recordedShowGuardRef.current = false
    writeChainRef.current = Promise.resolve()
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

    // Set before persist so account-identity churn cannot re-enter.
    recordedDayGuardRef.current = guardKey
    persist(withRecordedActiveDay(latest, dayKey))
  }, [account, accountId, persist, readLatestState, trackActiveDay])

  const shouldShow = shouldShowCommunitySupportPrompt(state)

  const recordShown = useCallback(() => {
    if (recordedShowGuardRef.current) return
    recordedShowGuardRef.current = true
    persist(withRecordedShow(readLatestState()))
  }, [persist, readLatestState])

  const skip = useCallback(() => {
    recordedShowGuardRef.current = true
    persist(withSkippedPrompt(readLatestState()))
  }, [persist, readLatestState])

  const takeAction = useCallback(
    (actionId: CommunitySupportActionId) => {
      recordedShowGuardRef.current = true
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
