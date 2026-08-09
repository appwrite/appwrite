/**
 * Pure analytics route helpers shared by client (`analytics.ts`) and the Bun
 * production server (`server-analytics.ts`).
 *
 * Must stay free of Vite-only constructs (`import.meta.env`, `?url` imports,
 * path aliases) so Bun can load it raw from `server.ts` without tsconfig paths.
 */

/**
 * Coarse product surface for Plausible custom properties.
 * Derived from the sanitized route template, not the raw pathname.
 */
export type AnalyticsSurface =
  | 'marketing'
  | 'console'
  | 'docs'
  | 'account'
  | 'auth'

export function getAnalyticsArea(routePath: string) {
  const parts = routePath.split('/').filter(Boolean)
  if (parts[0] === 'projects') return parts[2] ?? 'overview'
  if (parts[0] === 'organizations') return parts[2] ?? 'overview'
  return parts[0] ?? 'root'
}

/**
 * Map a route path to a coarse Plausible `surface` property.
 */
export function getAnalyticsSurface(routePath: string): AnalyticsSurface {
  const root = routePath.split('/').filter(Boolean)[0] ?? ''

  if (root === 'docs') return 'docs'
  if (root === 'account') return 'account'

  if (
    root === 'sign-in' ||
    root === 'sign-up' ||
    root === 'join' ||
    root === 'verify-email' ||
    root === 'auth' ||
    root === 'oauth2' ||
    root === 'reset' ||
    root === 'card'
  ) {
    return 'auth'
  }

  if (
    root === 'projects' ||
    root === 'organizations' ||
    root === 'upgrade' ||
    root === 'generator'
  ) {
    return 'console'
  }

  return 'marketing'
}
