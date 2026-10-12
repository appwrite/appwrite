import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { isPreLaunchModeEnabled } from '@/lib/pre-launch'

/** Reserved `/init/:segment` paths that are not personal ticket share pages. */
const INIT_NON_TICKET_SEGMENTS = new Set(['ticket', 'calendar', 'keynote'])

function normalizePathname(pathname: string | null | undefined): string {
  return (pathname ?? '/').replace(/\/+$/, '') || '/'
}

/** `/init/$ticketId` share pages. */
export function isInitTicketSharePath(
  pathname: string | null | undefined,
): boolean {
  const normalized = normalizePathname(pathname)
  const match = normalized.match(/^\/init\/([^/]+)$/)
  if (!match?.[1]) return false
  return !INIT_NON_TICKET_SEGMENTS.has(match[1])
}

/** `/init/$ticketId/og.png` Open Graph images for shared tickets. */
export function isInitTicketOgPath(pathname: string | null | undefined): boolean {
  const normalized = normalizePathname(pathname)
  return /^\/init\/[^/]+\/og\.png$/.test(normalized)
}

/**
 * Init week surfaces stay up during pre-launch even if the profile flag is off,
 * so the landing page, ticket share URLs, and OG images keep working.
 */
export function isInitSurfaceEnabled(cookieHeader?: string | null): boolean {
  return (
    getActiveProfileFeatures().init || isPreLaunchModeEnabled(cookieHeader)
  )
}
