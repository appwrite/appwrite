import { useLocation, useMatches } from '@tanstack/react-router'
import { useCallback, useMemo } from 'react'
import {
  type AnalyticsProps,
  getAnalyticsArea,
  getAnalyticsRoutePath,
  getAnalyticsRouteUrl,
  trackEvent,
} from '@/lib/analytics'

export function useAnalytics() {
  const location = useLocation()
  const matches = useMatches()
  const leafRoute = matches[matches.length - 1]

  const routePath = useMemo(
    () => getAnalyticsRoutePath(leafRoute?.routeId, location.pathname),
    [leafRoute?.routeId, location.pathname],
  )

  const track = useCallback(
    (eventName: string, props: AnalyticsProps = {}) => {
      trackEvent(eventName, props, { routePath })
    },
    [routePath],
  )

  return {
    area: getAnalyticsArea(routePath),
    routePath,
    routeUrl: getAnalyticsRouteUrl(routePath),
    track,
  }
}
