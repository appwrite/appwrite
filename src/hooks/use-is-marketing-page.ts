import { useLocation, useMatches } from '@tanstack/react-router'
import { useMemo } from 'react'
import { isMarketingPagePath } from '@/lib/marketing/is-marketing-page'
import { isMarketingRouteMatch } from '@/lib/marketing/route-static-data'

/**
 * True on marketing/public pages (home, pricing, docs, policies, etc.).
 * Prefers TanStack Router route staticData; falls back to pathname rules.
 */
export function useIsMarketingPage(): boolean {
  const matches = useMatches()
  const { pathname } = useLocation()

  return useMemo(
    () =>
      matches.some((match) => isMarketingRouteMatch(match)) ||
      isMarketingPagePath(pathname),
    [matches, pathname],
  )
}
