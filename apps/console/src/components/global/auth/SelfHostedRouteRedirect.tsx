import { useEffect } from 'react'
import { useLocation, useNavigate } from '@tanstack/react-router'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { isSelfHostedAllowedPath } from '@/lib/self-hosted-route-access'

/**
 * Debug-menu profile overrides apply after hydration, so a cloud SSR response
 * can still be on screen. Once the browser profile is self-hosted, leave
 * marketing and docs for sign-in. The current page stays mounted until
 * navigation finishes.
 */
export function SelfHostedRouteRedirect() {
  const { isSelfHosted } = useConsoleProfile()
  const location = useLocation()
  const navigate = useNavigate()

  useEffect(() => {
    if (!isSelfHosted) return
    if (isSelfHostedAllowedPath(location.pathname)) return
    void navigate({ to: '/sign-in', replace: true })
  }, [isSelfHosted, location.pathname, navigate])

  return null
}
