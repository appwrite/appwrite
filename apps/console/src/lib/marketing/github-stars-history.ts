export type GitHubStarWeek = {
  week: number
  total: number
}

export type GitHubStarHistoryPoint = {
  date: string
  stars: number
}

export function toIsoDateUtc(date: Date): string {
  return date.toISOString().slice(0, 10)
}

/**
 * Walk GitHub's newest-first weekly star additions backwards from the live
 * total so each point is a cumulative count.
 */
export function buildCumulativeStarHistory(
  currentStars: number,
  weeksNewestFirst: readonly GitHubStarWeek[],
  asOf: Date,
): GitHubStarHistoryPoint[] {
  let count = Math.max(0, Math.round(currentStars))
  const points: GitHubStarHistoryPoint[] = [
    { date: toIsoDateUtc(asOf), stars: count },
  ]

  for (const week of weeksNewestFirst) {
    if (!Number.isFinite(week.week) || !Number.isFinite(week.total)) continue
    count = Math.max(0, count - Math.max(0, Math.round(week.total)))
    const weekStart = new Date(week.week * 1000)
    if (Number.isNaN(weekStart.getTime())) continue
    points.push({
      date: toIsoDateUtc(weekStart),
      stars: count,
    })
  }

  return points.reverse()
}

/** Keep the last sample in each calendar month so the hover chart stays readable. */
export function downsampleStarHistoryMonthly(
  points: readonly GitHubStarHistoryPoint[],
): GitHubStarHistoryPoint[] {
  if (points.length === 0) return []

  const sorted = [...points].sort((a, b) => a.date.localeCompare(b.date))
  const byMonth = new Map<string, GitHubStarHistoryPoint>()
  for (const point of sorted) {
    if (!point.date || !Number.isFinite(point.stars)) continue
    byMonth.set(point.date.slice(0, 7), {
      date: point.date,
      stars: Math.max(0, Math.round(point.stars)),
    })
  }

  return [...byMonth.values()]
}

/** Stars added between the latest point and the last sample on or before `days` ago. */
export function starHistoryGrowthSince(
  points: readonly GitHubStarHistoryPoint[],
  days: number,
): number | null {
  if (points.length < 2 || !Number.isFinite(days) || days <= 0) return null

  const latest = points[points.length - 1]
  if (!latest) return null

  const cutoff = new Date(`${latest.date}T00:00:00.000Z`)
  if (Number.isNaN(cutoff.getTime())) return null
  cutoff.setUTCDate(cutoff.getUTCDate() - days)
  const cutoffIso = toIsoDateUtc(cutoff)

  let baseline: GitHubStarHistoryPoint | null = null
  for (const point of points) {
    if (point.date <= cutoffIso) baseline = point
  }
  if (!baseline || baseline.date === latest.date) return null

  return Math.max(0, latest.stars - baseline.stars)
}
