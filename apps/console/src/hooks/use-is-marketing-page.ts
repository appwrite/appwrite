import { useLocation, useMatches } from '@tanstack/react-router'
import { useMemo } from 'react'
import { isMarketingPage } from '@/lib/marketing/is-marketing-page'

/**
 * True on marketing/public pages (home, pricing, docs, policies, etc.).
 * Prefers TanStack Router route staticData; falls back to pathname rules.
 */
export function useIsMarketingPage(): boolean {
  const matches = useMatches()
  const { pathname } = useLocation()

  return useMemo(
    () => isMarketingPage({ pathname, matches }),
    [matches, pathname],
  )
}
