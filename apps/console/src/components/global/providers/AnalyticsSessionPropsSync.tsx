import { useEffect } from 'react'
import { useLocation, useMatches } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useI18n } from '@/lib/i18n'
import {
  getAnalyticsPlanFromBillingId,
  getAnalyticsRoutePath,
  getAnalyticsSurface,
  getPlausibleScriptSrc,
  setAnalyticsSessionProps,
  trackPageView,
} from '@/lib/analytics'
import { loadPlausibleScript } from '@/lib/cookie-consent/load-tracking-scripts'
import { useProject } from '@/lib/react-query/hooks'
import { organizationPlanQueryOptions } from '@/lib/react-query/hooks/organizations'
import { getConsoleRouteIds } from '@/lib/utils/page-title'

/**
 * Keeps Plausible session custom properties (auth, plan, lang) in sync and
 * records pageviews.
 *
 * Marketing and docs must not wait on `account.get`. That 401 round trip
 * delayed the first `/home` pageview until after most bounce visitors had
 * already left. Console / account / auth still wait so `auth` / `plan` are
 * correct. Org plan is still synced onto later events as soon as it loads.
 */
export function AnalyticsSessionPropsSync() {
  const location = useLocation()
  const matches = useMatches()
  const leafRoute = matches[matches.length - 1]
  const { account, isAuthenticated, isFetched } = useAuth()
  const { language } = useI18n()
  const accountUser = account as Models.User | undefined

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

  const { data: orgPlan } = useQuery({
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

  const routePath = getAnalyticsRoutePath(
    leafRoute?.routeId,
    location.pathname,
  )
  const surface = getAnalyticsSurface(routePath)
  const waitForAuth =
    surface === 'console' || surface === 'account' || surface === 'auth'
  const authGate = waitForAuth ? isFetched : true

  useEffect(() => {
    loadPlausibleScript()
    if (!getPlausibleScriptSrc() || typeof window === 'undefined' || !authGate)
      return

    trackPageView(routePath)
  }, [authGate, routePath])

  return null
}
