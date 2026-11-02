import { describe, expect, test } from 'bun:test'
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
    const days = ['2026-10-20', '2026-10-24', '2026-11-01']
    // Window is 10 days inclusive of today: 2026-10-24 .. 2026-11-02.
    expect(pruneGithubStarVisitDays(days, '2026-11-02')).toEqual([
      '2026-10-24',
      '2026-11-01',
    ])
  })

  test('drops future day keys as corrupt', () => {
    expect(
      pruneGithubStarVisitDays(['2026-11-05', '2026-11-01'], '2026-11-02'),
    ).toEqual(['2026-11-01'])
  })

  test('becomes eligible on the third distinct day inside the window', () => {
    expect(GITHUB_STAR_WIZARD_VISIT_THRESHOLD).toBe(3)
    expect(GITHUB_STAR_WIZARD_VISIT_WINDOW_DAYS).toBe(10)

    let days: string[] = []
    days = withRecordedGithubStarVisit(days, '2026-11-02')
    expect(isGithubStarWizardEligible(days, '2026-11-02')).toBe(false)

    days = withRecordedGithubStarVisit(days, '2026-11-04')
    expect(isGithubStarWizardEligible(days, '2026-11-04')).toBe(false)

    days = withRecordedGithubStarVisit(days, '2026-11-08')
    expect(isGithubStarWizardEligible(days, '2026-11-08')).toBe(true)
  })

  test('visits spread wider than the window never qualify', () => {
    let days: string[] = []
    days = withRecordedGithubStarVisit(days, '2026-10-01')
    days = withRecordedGithubStarVisit(days, '2026-10-15')
    days = withRecordedGithubStarVisit(days, '2026-10-29')
    // Each visit aged out before the next two arrived.
    expect(isGithubStarWizardEligible(days, '2026-10-29')).toBe(false)
    expect(days).toEqual(['2026-10-29'])
  })

  test('three visits on window boundaries qualify', () => {
    const days = ['2026-10-24', '2026-10-28', '2026-11-02']
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
})
