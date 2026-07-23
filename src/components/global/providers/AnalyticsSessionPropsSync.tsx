import { useEffect, useState } from 'react'
import { useLocation, useMatches } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useI18n } from '@/lib/i18n'
import {
  getAnalyticsPlanFromBillingId,
  getAnalyticsRoutePath,
  setAnalyticsSessionProps,
  trackPageView,
} from '@/lib/analytics'
import {
  canTrackAnalytics,
  subscribeCookieConsent,
} from '@/lib/cookie-consent/consent-state'
import { useProject } from '@/lib/react-query/hooks'
import { organizationPlanQueryOptions } from '@/lib/react-query/hooks/organizations'
import { getConsoleRouteIds } from '@/lib/utils/page-title'

/**
 * Keeps Plausible session custom properties (auth, plan, lang) in sync and
 * records pageviews once auth (and plan, when an org is known) have settled.
 */
export function AnalyticsSessionPropsSync() {
  const location = useLocation()
  const matches = useMatches()
  const leafRoute = matches[matches.length - 1]
  const { account, isAuthenticated, isFetched } = useAuth()
  const { language } = useI18n()
  const accountUser = account as Models.User | undefined
  const [consentTick, setConsentTick] = useState(0)

  const { projectId, orgId: orgIdFromUrl } = getConsoleRouteIds(
    location.pathname,
  )
  const { project, isLoading: projectLoading } = useProject(projectId)

  const currentOrgId = projectId
    ? (project?.teamId ?? undefined)
    : (orgIdFromUrl ??
      (accountUser?.prefs?.organization as string | undefined))

  // On project routes, wait for project so teamId (org) is known before plan.
  const orgIdResolved = !projectId || !isAuthenticated || !projectLoading

  const { data: orgPlan, isFetched: planFetched } = useQuery({
    ...organizationPlanQueryOptions(currentOrgId),
    enabled:
      isAuthenticated &&
      orgIdResolved &&
      !!currentOrgId &&
      typeof window !== 'undefined',
  })

  const auth = isFetched && isAuthenticated ? 'user' : 'guest'
  const plan =
    auth === 'guest'
      ? 'none'
      : getAnalyticsPlanFromBillingId(orgPlan?.$id)

  setAnalyticsSessionProps({
    auth,
    plan,
    lang: language,
  })

  const sessionReady =
    isFetched &&
    orgIdResolved &&
    (auth === 'guest' || !currentOrgId || planFetched)

  useEffect(() => {
    return subscribeCookieConsent(() => {
      setConsentTick((tick) => tick + 1)
    })
  }, [])

  useEffect(() => {
    if (!canTrackAnalytics() || typeof window === 'undefined' || !sessionReady)
      return

    const routePath = getAnalyticsRoutePath(
      leafRoute?.routeId,
      location.pathname,
    )
    trackPageView(routePath)
  }, [sessionReady, consentTick, leafRoute?.routeId, location.pathname])

  return null
}
