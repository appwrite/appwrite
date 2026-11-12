import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import {
  COMMUNITY_SUPPORT_PROMPT_HOLD_OFF_MS,
  COMMUNITY_SUPPORT_UNIQUE_DAYS_THRESHOLD,
  isCommunitySupportPromptEngaged,
} from '@/lib/community/support-prompt'
import {
  GITHUB_STAR_WIZARD_VISIT_THRESHOLD,
  GITHUB_STAR_WIZARD_VISIT_WINDOW_DAYS,
  isGithubStarWizardEligible,
  normalizeGithubStarVisitDays,
  parseVisitDayKeyUtcMs,
  pruneGithubStarVisitDays,
  withRecordedGithubStarVisit,
} from '@/lib/console-banners/github-star-wizard'
import {
  clearGuestConsoleBannerDismiss,
  getAccountScopedConsoleBannerId,
  isGuestConsoleBannerDismissed,
  persistGuestConsoleBannerDismiss,
} from '@/lib/console-banners/guest-dismissed-banners'
import {
  clearGithubStarVisitDaysFromPrefs,
  isConsoleBannerDismissed,
  mergeDismissedBannerPrefs,
  mergeGithubStarVisitDaysIntoPrefs,
  parseGithubStarVisitDayPrefs,
  USER_PREFS_KEY_GITHUB_STAR_VISIT_DAYS,
} from '@/lib/user-prefs-keys'

describe('GitHub star wizard visit tracking', () => {
  test('parses valid day keys and rejects corrupt ones', () => {
    expect(parseVisitDayKeyUtcMs('2026-11-02')).toBe(
      Date.UTC(2026, 10, 2, 0, 0, 0, 0),
    )
    expect(parseVisitDayKeyUtcMs('2026-13-02')).toBeNull()
    expect(parseVisitDayKeyUtcMs('2026-00-02')).toBeNull()
    expect(parseVisitDayKeyUtcMs('2026-11-32')).toBeNull()
    expect(parseVisitDayKeyUtcMs('2026-11')).toBeNull()
    expect(parseVisitDayKeyUtcMs('26-11-02')).toBeNull()
    expect(parseVisitDayKeyUtcMs('2026-1a-02')).toBeNull()
    expect(parseVisitDayKeyUtcMs('')).toBeNull()
  })

  test('normalizes to sorted unique valid keys', () => {
    expect(
      normalizeGithubStarVisitDays([
        '2026-11-02',
        'garbage',
        '2026-10-30',
        '2026-11-02',
      ]),
    ).toEqual(['2026-10-30', '2026-11-02'])
  })

  test('recording a visit adds today once and keeps the list pruned', () => {
    const first = withRecordedGithubStarVisit([], '2026-11-02')
    expect(first).toEqual(['2026-11-02'])

    const repeat = withRecordedGithubStarVisit(first, '2026-11-02')
    expect(repeat).toEqual(['2026-11-02'])

    const later = withRecordedGithubStarVisit(repeat, '2026-11-05')
    expect(later).toEqual(['2026-11-02', '2026-11-05'])
  })

  test('prunes visits that fell out of the rolling window', () => {
    const days = ['2026-10-16', '2026-10-20', '2026-11-01']
    // Window is 14 days inclusive of today: 2026-10-20 .. 2026-11-02.
    expect(pruneGithubStarVisitDays(days, '2026-11-02')).toEqual([
      '2026-10-20',
      '2026-11-01',
    ])
  })

  test('drops future day keys as corrupt', () => {
    expect(
      pruneGithubStarVisitDays(['2026-11-05', '2026-11-01'], '2026-11-02'),
    ).toEqual(['2026-11-01'])
  })

  test('stores at most the threshold, keeping the most recent days', () => {
    let days: string[] = []
    for (const day of [
      '2026-10-25',
      '2026-10-26',
      '2026-10-27',
      '2026-10-28',
      '2026-10-29',
      '2026-10-30',
      '2026-10-31',
    ]) {
      days = withRecordedGithubStarVisit(days, day)
    }
    expect(days).toEqual([
      '2026-10-27',
      '2026-10-28',
      '2026-10-29',
      '2026-10-30',
      '2026-10-31',
    ])
    // A capped list is still a well-formed, tiny prefs value.
    expect(days.join(',').length).toBeLessThanOrEqual(
      GITHUB_STAR_WIZARD_VISIT_THRESHOLD * 11,
    )
  })

  test('becomes eligible on the fifth distinct day inside the window', () => {
    expect(GITHUB_STAR_WIZARD_VISIT_THRESHOLD).toBe(5)
    expect(GITHUB_STAR_WIZARD_VISIT_WINDOW_DAYS).toBe(14)

    let days: string[] = []
    const visits = [
      '2026-11-02',
      '2026-11-04',
      '2026-11-07',
      '2026-11-10',
      '2026-11-15',
    ]
    visits.forEach((day, index) => {
      days = withRecordedGithubStarVisit(days, day)
      expect(isGithubStarWizardEligible(days, day)).toBe(
        index === visits.length - 1,
      )
    })
  })

  test('visits spread wider than the window never qualify', () => {
    let days: string[] = []
    for (const day of [
      '2026-10-01',
      '2026-10-06',
      '2026-10-11',
      '2026-10-16',
      '2026-10-21',
    ]) {
      days = withRecordedGithubStarVisit(days, day)
    }
    // 2026-10-01 aged out of the 14-day window before the fifth visit.
    expect(days).toEqual(['2026-10-11', '2026-10-16', '2026-10-21'])
    expect(isGithubStarWizardEligible(days, '2026-10-21')).toBe(false)
  })

  test('five visits on window boundaries qualify', () => {
    const days = [
      '2026-10-20',
      '2026-10-23',
      '2026-10-27',
      '2026-10-30',
      '2026-11-02',
    ]
    expect(isGithubStarWizardEligible(days, '2026-11-02')).toBe(true)
  })

  test('visit day prefs round-trip through the flat prefs key', () => {
    const prefs = mergeGithubStarVisitDaysIntoPrefs({ existing: 'value' }, [
      '2026-11-01',
      '2026-11-02',
    ])
    expect(prefs[USER_PREFS_KEY_GITHUB_STAR_VISIT_DAYS]).toBe(
      '2026-11-01,2026-11-02',
    )
    expect(prefs.existing).toBe('value')
    expect(parseGithubStarVisitDayPrefs(prefs)).toEqual([
      '2026-11-01',
      '2026-11-02',
    ])
    expect(parseGithubStarVisitDayPrefs(undefined)).toEqual([])
    expect(parseGithubStarVisitDayPrefs({})).toEqual([])
  })

  test('dismissal drops the visit key and keeps everything else', () => {
    const prefs = clearGithubStarVisitDaysFromPrefs(
      mergeDismissedBannerPrefs(
        {
          existing: 'value',
          [USER_PREFS_KEY_GITHUB_STAR_VISIT_DAYS]: '2026-11-01,2026-11-02',
        },
        'github-star-wizard-2026',
      ),
    )
    expect(USER_PREFS_KEY_GITHUB_STAR_VISIT_DAYS in prefs).toBe(false)
    expect(prefs.existing).toBe('value')
    expect(isConsoleBannerDismissed(prefs, 'github-star-wizard-2026')).toBe(
      true,
    )
  })
})

describe('community-support deferral', () => {
  const baseState = {
    uniqueDayCount: 0,
    lastActiveDay: null,
    shownCount: 0,
    lastShownAt: null,
    actionTakenAt: null,
    actionId: null,
  }
  const now = new Date('2026-11-02T12:00:00.000Z')

  test('engaged while the community wizard is due', () => {
    expect(
      isCommunitySupportPromptEngaged(
        {
          ...baseState,
          uniqueDayCount: COMMUNITY_SUPPORT_UNIQUE_DAYS_THRESHOLD,
        },
        now,
      ),
    ).toBe(true)
  })

  test('engaged while a fresh lastShownAt stamp is inside the hold-off', () => {
    // Opening (and skipping) stamp lastShownAt, flipping "due" false while
    // the wizard is still on screen; the hold-off covers that gap.
    const state = {
      ...baseState,
      uniqueDayCount: COMMUNITY_SUPPORT_UNIQUE_DAYS_THRESHOLD,
      shownCount: 1,
      lastShownAt: new Date(now.getTime() - 60 * 1000).toISOString(),
    }
    expect(isCommunitySupportPromptEngaged(state, now)).toBe(true)
  })

  test('disengaged once the stamp ages past the hold-off', () => {
    const state = {
      ...baseState,
      uniqueDayCount: COMMUNITY_SUPPORT_UNIQUE_DAYS_THRESHOLD,
      shownCount: 1,
      actionTakenAt: now.toISOString(),
      lastShownAt: new Date(
        now.getTime() - COMMUNITY_SUPPORT_PROMPT_HOLD_OFF_MS - 60 * 1000,
      ).toISOString(),
    }
    expect(isCommunitySupportPromptEngaged(state, now)).toBe(false)
  })

  test('disengaged for a user who never saw the community wizard', () => {
    expect(isCommunitySupportPromptEngaged(baseState, now)).toBe(false)
  })
})

describe('device dismissal marker', () => {
  const globals = globalThis as { window?: unknown }
  const originalWindow = globals.window
  const bannerId = 'github-star-wizard-2026'

  beforeEach(() => {
    const storage = new Map<string, string>()
    globals.window = {
      localStorage: {
        getItem: (key: string) => storage.get(key) ?? null,
        setItem: (key: string, value: string) => void storage.set(key, value),
        removeItem: (key: string) => void storage.delete(key),
      },
    }
  })

  afterEach(() => {
    globals.window = originalWindow
  })

  test('is scoped to the account that dismissed', () => {
    persistGuestConsoleBannerDismiss(
      getAccountScopedConsoleBannerId(bannerId, 'user-a'),
    )
    expect(
      isGuestConsoleBannerDismissed(
        getAccountScopedConsoleBannerId(bannerId, 'user-a'),
      ),
    ).toBe(true)
    expect(
      isGuestConsoleBannerDismissed(
        getAccountScopedConsoleBannerId(bannerId, 'user-b'),
      ),
    ).toBe(false)
  })

  test('reset clears every account-scoped marker for the banner', () => {
    persistGuestConsoleBannerDismiss(
      getAccountScopedConsoleBannerId(bannerId, 'user-a'),
    )
    persistGuestConsoleBannerDismiss('other-banner')
    clearGuestConsoleBannerDismiss(bannerId)
    expect(
      isGuestConsoleBannerDismissed(
        getAccountScopedConsoleBannerId(bannerId, 'user-a'),
      ),
    ).toBe(false)
    expect(isGuestConsoleBannerDismissed('other-banner')).toBe(true)
  })
})
