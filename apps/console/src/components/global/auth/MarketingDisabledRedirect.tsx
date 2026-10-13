import { useEffect, useState } from 'react'
import { useMatches, useNavigate } from '@tanstack/react-router'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { isMarketingGatedRouteId } from '@/lib/marketing/marketing-route-guard'

/**
 * Client-side lock for marketing pages and docs when `marketing` is off.
 * Covers debug profile overrides, which SSR cannot see, so the route
 * `beforeLoad` guards pass on the server for the first document request.
 */
export function MarketingDisabledRedirect() {
  const matches = useMatches()
  const navigate = useNavigate()
  const { features } = useConsoleProfile()
  const [profileReady, setProfileReady] = useState(false)
  const onGatedRoute = matches.some((match) =>
    isMarketingGatedRouteId(match.routeId),
  )

  useEffect(() => {
    setProfileReady(true)
  }, [])

  useEffect(() => {
    if (!profileReady) return
    if (features.marketing || !onGatedRoute) return
    void navigate({ to: '/', replace: true })
  }, [profileReady, features.marketing, onGatedRoute, navigate])

  return null
}
