import { useCallback, useEffect, useMemo, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import {
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
  shouldShowCommunitySupportPrompt,
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

  useEffect(() => {
    recordedDayGuardRef.current = null
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

    void updateAccountPrefs(
      mergeGithubStarVisitDaysIntoPrefs(currentPrefs, nextDays),
      'github-star-prompt',
    )
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

  // Hold off while the community-support wizard is due so two fullscreen
  // prompts never compete for the same session.
  const communitySupportDue = useMemo(() => {
    const communitySupport = parseCommunitySupportPrefs(prefs)
    return shouldShowCommunitySupportPrompt({
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
    !communitySupportDue &&
    isGithubStarWizardEligible(visitDays, todayKey)

  const dismissBanner = useDismissConsoleBanner()
  const { mutate: dismissBannerMutate } = dismissBanner
  const dismiss = useCallback(() => {
    dismissBannerMutate(GITHUB_STAR_WIZARD_BANNER_ID)
  }, [dismissBannerMutate])

  return {
    visitDays,
    dismissed,
    shouldShow,
    dismiss,
    todayKey,
  }
}
