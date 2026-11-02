/**
 * GitHub star wizard: a one-time fullscreen ask shown to recurring console
 * users. Visit days are tracked in account prefs
 * (`console.githubStarVisitDays`); dismissal is permanent and goes through
 * `console.dismissedBanners` like every other console banner.
 * The banner id lives in the catalog: `GITHUB_STAR_WIZARD_BANNER_ID`.
 */

/** Distinct console visit days required before the wizard can show. */
export const GITHUB_STAR_WIZARD_VISIT_THRESHOLD = 3

/** Rolling window (days, inclusive of today) the visits must fall into. */
export const GITHUB_STAR_WIZARD_VISIT_WINDOW_DAYS = 10

const DAY_MS = 24 * 60 * 60 * 1000

function isDigits(value: string): boolean {
  if (value.length === 0) return false
  for (const char of value) {
    if (char < '0' || char > '9') return false
  }
  return true
}

/** Parse a `YYYY-MM-DD` day key (as written by `getLocalDayKey`) to UTC ms. */
export function parseVisitDayKeyUtcMs(dayKey: string): number | null {
  const parts = dayKey.split('-')
  if (parts.length !== 3) return null
  const [year, month, day] = parts
  if (year.length !== 4 || month.length !== 2 || day.length !== 2) return null
  if (!isDigits(year) || !isDigits(month) || !isDigits(day)) return null
  const monthNumber = Number(month)
  const dayNumber = Number(day)
  if (monthNumber < 1 || monthNumber > 12) return null
  if (dayNumber < 1 || dayNumber > 31) return null
  return Date.UTC(Number(year), monthNumber - 1, dayNumber)
}

/** Drop invalid keys, dedupe, and sort ascending. */
export function normalizeGithubStarVisitDays(
  days: readonly string[],
): string[] {
  const valid = days.filter((day) => parseVisitDayKeyUtcMs(day) !== null)
  return [...new Set(valid)].sort()
}

/**
 * Keep only the visit days inside the rolling window that ends at `todayKey`
 * (inclusive). Future day keys are dropped as corrupt.
 */
export function pruneGithubStarVisitDays(
  days: readonly string[],
  todayKey: string,
): string[] {
  const todayMs = parseVisitDayKeyUtcMs(todayKey)
  const normalized = normalizeGithubStarVisitDays(days)
  if (todayMs === null) return normalized
  const windowStartMs =
    todayMs - (GITHUB_STAR_WIZARD_VISIT_WINDOW_DAYS - 1) * DAY_MS
  return normalized.filter((day) => {
    const dayMs = parseVisitDayKeyUtcMs(day)
    return dayMs !== null && dayMs >= windowStartMs && dayMs <= todayMs
  })
}

/** Record today's visit and prune the list to the rolling window. */
export function withRecordedGithubStarVisit(
  days: readonly string[],
  todayKey: string,
): string[] {
  return pruneGithubStarVisitDays([...days, todayKey], todayKey)
}

/** Whether enough distinct visit days landed inside the rolling window. */
export function isGithubStarWizardEligible(
  days: readonly string[],
  todayKey: string,
): boolean {
  return (
    pruneGithubStarVisitDays(days, todayKey).length >=
    GITHUB_STAR_WIZARD_VISIT_THRESHOLD
  )
}
