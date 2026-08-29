/** How long a project feature stays marked as new after its launch date. */
export const PROJECT_FEATURE_NEW_BADGE_MONTHS = 1

/**
 * UTC launch dates (YYYY-MM-DD) for project console surfaces that should show a
 * temporary "New" badge (left nav items, terminal, etc.).
 */
export const PROJECT_FEATURE_LAUNCH_DATES: Record<string, string> = {
  firewall: '2026-08-30',
  explorer: '2026-08-30',
  activity: '2026-08-30',
  realtime: '2026-08-30',
  usage: '2026-08-30',
  terminal: '2026-08-30',
}

function parseUtcDate(isoDate: string): Date {
  const [year, month, day] = isoDate.split('-').map(Number)
  return new Date(Date.UTC(year, (month ?? 1) - 1, day ?? 1))
}

function addUtcMonths(date: Date, months: number): Date {
  const result = new Date(date.getTime())
  result.setUTCMonth(result.getUTCMonth() + months)
  return result
}

export function isProjectFeatureNew(
  id: string,
  now: Date = new Date(),
): boolean {
  const launchedAt = PROJECT_FEATURE_LAUNCH_DATES[id]
  if (!launchedAt) return false

  const launch = parseUtcDate(launchedAt)
  if (Number.isNaN(launch.getTime())) return false

  return (
    now.getTime() <
    addUtcMonths(launch, PROJECT_FEATURE_NEW_BADGE_MONTHS).getTime()
  )
}
