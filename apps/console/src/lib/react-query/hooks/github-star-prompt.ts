import { useCallback, useEffect, useMemo, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import {
  commitConsoleAccountToCaches,
  getConsoleAccountFromCache,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
  useDismissConsoleBanner,
  type ConsoleAccountCache,
} from '@/lib/react-query/hooks/auth'
import {
  isConsoleBannerDismissed,
  mergeGithubStarVisitDaysIntoPrefs,
  parseCommunitySupportPrefs,
  parseGithubStarVisitDayPrefs,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import {
  getConsoleBannerById,
  GITHUB_STAR_WIZARD_BANNER_ID,
  isConsoleBannerScheduled,
} from '@/lib/console-banners/catalog'
import {
  isGithubStarWizardEligible,
  withRecordedGithubStarVisit,
} from '@/lib/console-banners/github-star-wizard'
import {
  getLocalDayKey,
  isCommunitySupportPromptEngaged,
  type CommunitySupportActionId,
} from '@/lib/community/support-prompt'

function isAccountUser(
  value: ConsoleAccountCache | undefined,
): value is Models.User {
  return !!value && typeof value === 'object' && '$id' in value
}

/**
 * Tracks distinct console visit days in account prefs
 * (`console.githubStarVisitDays`) and decides when the GitHub star wizard is
 * due. Dismissal is permanent via `console.dismissedBanners`.
 */
export function useGithubStarPrompt(
  account: ConsoleAccountCache | undefined,
  options?: { trackVisits?: boolean },
) {
  const queryClient = useQueryClient()
  const trackVisits = options?.trackVisits ?? true
  const accountId = isAccountUser(account) ? account.$id : undefined
  const accountRef = useRef(account)
  accountRef.current = account

  /** One visit write per account+calendar day (prevents re-entry storms). */
  const recordedDayGuardRef = useRef<string | null>(null)
  /** In-flight visit write, so dismissal can wait it out (see `dismiss`). */
  const visitWriteRef = useRef<Promise<void>>(Promise.resolve())

  useEffect(() => {
    recordedDayGuardRef.current = null
    visitWriteRef.current = Promise.resolve()
  }, [accountId])

  const prefs = account?.prefs as UserPrefs | undefined
  const todayKey = getLocalDayKey()
  const visitDays = useMemo(() => parseGithubStarVisitDayPrefs(prefs), [prefs])
  const dismissed = isConsoleBannerDismissed(
    prefs,
    GITHUB_STAR_WIZARD_BANNER_ID,
  )

  /** Record today's visit when needed; the list self-prunes to the window. */
  useEffect(() => {
    if (!account || !trackVisits || dismissed) return

    const guardKey = `${accountId ?? 'unknown'}:${todayKey}`
    if (recordedDayGuardRef.current === guardKey) return
    recordedDayGuardRef.current = guardKey

    const cached = getConsoleAccountFromCache(queryClient)
    const currentPrefs = (cached?.prefs ??
      accountRef.current?.prefs ??
      {}) as UserPrefs
    const currentDays = parseGithubStarVisitDayPrefs(currentPrefs)
    const nextDays = withRecordedGithubStarVisit(currentDays, todayKey)
    if (nextDays.join(',') === currentDays.join(',')) return

    const merged = mergeGithubStarVisitDaysIntoPrefs(currentPrefs, nextDays)
    // Commit optimistically so pref writers queued in this same render (the
    // community-support hook, banner dismissals) read a cache that already
    // carries today's visit; force the API write past the now-empty diff.
    if (isAccountUser(cached)) {
      commitConsoleAccountToCaches(queryClient, {
        ...cached,
        prefs: merged,
      } as Models.User)
    }
    visitWriteRef.current = updateAccountPrefs(merged, 'github-star-prompt', {
      force: true,
    })
      .then((updatedAccount) => {
        if (!updatedAccount) return
        syncConsoleAccountAfterMutation(queryClient, {
          apiResult: updatedAccount,
        })
      })
      .catch((error) => {
        console.error('[github-star-prompt] failed to record visit day', error)
      })
  }, [account, accountId, dismissed, queryClient, todayKey, trackVisits])

  // Stay out of the way while the community-support wizard is due, open, or
  // was shown within its hold-off window: opening stamps `lastShownAt` (which
  // flips its "due" check false mid-impression), so "due" alone would let
  // both fullscreen takeovers stack or chain in one session.
  const communitySupportEngaged = useMemo(() => {
    const communitySupport = parseCommunitySupportPrefs(prefs)
    return isCommunitySupportPromptEngaged({
      ...communitySupport,
      actionId:
        (communitySupport.actionId as CommunitySupportActionId | null) ?? null,
    })
  }, [prefs])

  const banner = getConsoleBannerById(GITHUB_STAR_WIZARD_BANNER_ID)
  const scheduled = banner ? isConsoleBannerScheduled(banner) : false

  const shouldShow =
    !dismissed &&
    scheduled &&
    !communitySupportEngaged &&
    isGithubStarWizardEligible(visitDays, todayKey)

  const dismissBanner = useDismissConsoleBanner()
  const { mutate: dismissBannerMutate } = dismissBanner
  const dismiss = useCallback(() => {
    // Wait out an in-flight visit write so its full-prefs payload cannot land
    // after the dismissal and resurrect the wizard.
    void visitWriteRef.current
      .catch(() => undefined)
      .then(() => dismissBannerMutate(GITHUB_STAR_WIZARD_BANNER_ID))
  }, [dismissBannerMutate])

  return {
    visitDays,
    dismissed,
    shouldShow,
    dismiss,
    todayKey,
  }
}
