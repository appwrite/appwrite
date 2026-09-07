export type ConsoleBannerScope = 'project'

export type ConsoleBannerDefinition = {
  id: string
  title: string
  scope: ConsoleBannerScope
  /** Inclusive start (UTC ms). */
  startMs: number
  /** Inclusive end (UTC ms). */
  endMs: number
  /** When true, only shown on cloud profile. */
  cloudOnly?: boolean
}

/** Stable id stored in `console.dismissedBanners` user prefs. */
export const POSTGRES_PROMO_BANNER_ID = 'postgres-promo-2026'

/**
 * Promo window: one month starting the week of 2026-09-14 (UTC).
 * Inclusive start, inclusive end (through end of day UTC).
 */
export const POSTGRES_PROMO_BANNER_START_MS = Date.UTC(2026, 8, 14, 0, 0, 0, 0)
export const POSTGRES_PROMO_BANNER_END_MS = Date.UTC(
  2026,
  9,
  14,
  23,
  59,
  59,
  999,
)

/** Registered project-scoped console header banners (promo strips). */
export const CONSOLE_BANNERS: readonly ConsoleBannerDefinition[] = [
  {
    id: POSTGRES_PROMO_BANNER_ID,
    title: 'Appwrite now speaks PostgreSQL',
    scope: 'project',
    startMs: POSTGRES_PROMO_BANNER_START_MS,
    endMs: POSTGRES_PROMO_BANNER_END_MS,
    cloudOnly: true,
  },
] as const

export function getConsoleBannerById(
  id: string,
): ConsoleBannerDefinition | undefined {
  return CONSOLE_BANNERS.find((banner) => banner.id === id)
}

export type ConsoleBannerScheduleStatus = 'upcoming' | 'active' | 'expired'

export function getConsoleBannerScheduleStatus(
  banner: ConsoleBannerDefinition,
  now: Date = new Date(),
): ConsoleBannerScheduleStatus {
  const ms = now.getTime()
  if (ms < banner.startMs) return 'upcoming'
  if (ms > banner.endMs) return 'expired'
  return 'active'
}

export function isConsoleBannerScheduled(
  banner: ConsoleBannerDefinition,
  now: Date = new Date(),
): boolean {
  return getConsoleBannerScheduleStatus(banner, now) === 'active'
}

export function formatConsoleBannerUtcRange(banner: ConsoleBannerDefinition): string {
  const fmt = new Intl.DateTimeFormat('en-US', {
    timeZone: 'UTC',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  })
  return `${fmt.format(new Date(banner.startMs))} – ${fmt.format(new Date(banner.endMs))} UTC`
}
