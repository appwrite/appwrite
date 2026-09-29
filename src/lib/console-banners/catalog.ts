import type { AnalyticsActionId } from '@/lib/analytics-actions'
import type {
  ConsoleBannerCardAspectRatio,
  ConsoleBannerCardSize,
} from '@/lib/console-banners/floating-card-layout'

export type ConsoleBannerScope = 'project' | 'console'
export type ConsoleBannerPlacement = 'header' | 'bottom-left' | 'fullscreen'

export type ConsoleBannerDefinition = {
  id: string
  title: string
  scope: ConsoleBannerScope
  placement?: ConsoleBannerPlacement
  /** Bottom-left floating card width variant (`large` = 300px max width). */
  cardSize?: ConsoleBannerCardSize
  /** When `square`, fixed 1:1 card (prefer explicit layout on one-off promos). */
  cardAspectRatio?: ConsoleBannerCardAspectRatio
  /** Inclusive start (UTC ms). */
  startMs: number
  /** Inclusive end (UTC ms). */
  endMs: number
  /** When true, only shown on cloud profile. */
  cloudOnly?: boolean
  /** CTA analytics action id (`data-analytics` / Plausible event). */
  event: AnalyticsActionId
}

/** Stable id stored in `console.dismissedBanners` user prefs. */
export const PRODUCT_HUNT_BANNER_ID = 'product-hunt-2026-09-16'
export const POSTGRES_PROMO_BANNER_ID = 'postgres-promo-2026'
export const START_PROMO_BANNER_ID = 'start-promo-india-2026-09'
export const INIT_RECAP_PROMO_BANNER_ID = 'init-recap-promo-2026-09'
export const NATIVE_OAUTH_PROMO_BANNER_ID = 'native-oauth-promo-2026-09'
export const FIREWALL_PROMO_BANNER_ID = 'firewall-promo-2026-09'

/** Appwrite Start India promo: 14 days from launch (inclusive, UTC). */
export const START_PROMO_BANNER_START_MS = Date.UTC(2026, 8, 21, 0, 0, 0, 0)
export const START_PROMO_BANNER_END_MS = Date.UTC(
  2026,
  9,
  4,
  23,
  59,
  59,
  999,
)

/**
 * Product Hunt launch day: 16 Sep 2026, start of day through end of day Pacific Time
 * (PDT, UTC-7). Inclusive start, inclusive end.
 */
export const PRODUCT_HUNT_BANNER_START_MS = new Date(
  '2026-09-16T00:00:00.000-07:00',
).getTime()
export const PRODUCT_HUNT_BANNER_END_MS = new Date(
  '2026-09-16T23:59:59.999-07:00',
).getTime()

/**
 * Postgres promo resumes after Product Hunt (17 Sep 2026, start of day Pacific)
 * and runs through the rest of the week (Sun 20 Sep 2026, end of day Pacific).
 * Inclusive start, inclusive end.
 */
export const POSTGRES_PROMO_BANNER_START_MS = new Date(
  '2026-09-17T00:00:00.000-07:00',
).getTime()
export const POSTGRES_PROMO_BANNER_END_MS = new Date(
  '2026-09-20T23:59:59.999-07:00',
).getTime()

/**
 * Init recap floating promo: after Init week through end of week (Sun 13 Sep 2026 UTC).
 */
export const INIT_RECAP_PROMO_BANNER_START_MS = Date.UTC(2026, 8, 5, 0, 0, 0, 0)
export const INIT_RECAP_PROMO_BANNER_END_MS = Date.UTC(
  2026,
  8,
  13,
  23,
  59,
  59,
  999,
)

/**
 * Native OAuth project promo: seven days from launch (inclusive, UTC).
 */
export const NATIVE_OAUTH_PROMO_BANNER_START_MS = Date.UTC(2026, 8, 24, 0, 0, 0, 0)
export const NATIVE_OAUTH_PROMO_BANNER_END_MS = Date.UTC(
  2026,
  8,
  30,
  23,
  59,
  59,
  999,
)

/**
 * Firewall fullscreen takeover: two weeks from launch (inclusive, UTC).
 */
export const FIREWALL_PROMO_BANNER_START_MS = Date.UTC(2026, 8, 28, 0, 0, 0, 0)
export const FIREWALL_PROMO_BANNER_END_MS = Date.UTC(
  2026,
  9,
  11,
  23,
  59,
  59,
  999,
)

/** Registered console promo banners (header strips, floating cards, takeovers). */
export const CONSOLE_BANNERS: readonly ConsoleBannerDefinition[] = [
  {
    id: INIT_RECAP_PROMO_BANNER_ID,
    title: 'Catch up on Init',
    scope: 'console',
    placement: 'bottom-left',
    startMs: INIT_RECAP_PROMO_BANNER_START_MS,
    endMs: INIT_RECAP_PROMO_BANNER_END_MS,
    cloudOnly: true,
    event: 'init-recap-promo-banner-view-recap',
  },
  {
    id: PRODUCT_HUNT_BANNER_ID,
    title: 'Appwrite 2.0 is launching on Product Hunt today',
    scope: 'console',
    placement: 'header',
    startMs: PRODUCT_HUNT_BANNER_START_MS,
    endMs: PRODUCT_HUNT_BANNER_END_MS,
    cloudOnly: true,
    event: 'product-hunt-banner-upvote',
  },
  {
    id: POSTGRES_PROMO_BANNER_ID,
    title: 'Appwrite now speaks PostgreSQL',
    scope: 'project',
    placement: 'header',
    startMs: POSTGRES_PROMO_BANNER_START_MS,
    endMs: POSTGRES_PROMO_BANNER_END_MS,
    cloudOnly: true,
    event: 'postgres-promo-banner-try-now',
  },
  {
    id: START_PROMO_BANNER_ID,
    title: 'Appwrite Start for India',
    scope: 'console',
    placement: 'header',
    startMs: START_PROMO_BANNER_START_MS,
    endMs: START_PROMO_BANNER_END_MS,
    cloudOnly: true,
    event: 'start-promo-banner-learn-more',
  },
  {
    id: NATIVE_OAUTH_PROMO_BANNER_ID,
    title: 'Add native OAuth to your app',
    scope: 'project',
    placement: 'bottom-left',
    startMs: NATIVE_OAUTH_PROMO_BANNER_START_MS,
    endMs: NATIVE_OAUTH_PROMO_BANNER_END_MS,
    cloudOnly: true,
    event: 'native-oauth-promo-banner-open-settings',
  },
  {
    id: FIREWALL_PROMO_BANNER_ID,
    title: 'Appwrite Firewall',
    scope: 'console',
    placement: 'fullscreen',
    startMs: FIREWALL_PROMO_BANNER_START_MS,
    endMs: FIREWALL_PROMO_BANNER_END_MS,
    cloudOnly: true,
    event: 'firewall-promo-banner-learn-more',
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
