import { useEffect } from 'react'
import { useLocation } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useProject } from '@/lib/react-query/hooks'
import { organizationPlanQueryOptions } from '@/lib/react-query/hooks/organizations'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { isMarketingPage } from '@/lib/marketing/is-marketing-page'
import {
  captureExceptionWithContext,
  isSentryReportingEnabled,
} from '@/lib/sentry/report-error'

const isSentryEnabled = () => isSentryReportingEnabled()

export { captureExceptionWithContext }

/**
 * Extracts project ID from URL pathname
 * Expected format: /projects/:projectId/...
 */
function extractProjectId(pathname: string): string | null {
  const match = pathname.match(/\/projects\/([^/]+)/)
  return match?.[1] || null
}

/**
 * Extracts organization ID from URL pathname
 * Expected format: /organizations/:orgId/...
 */
function extractOrgId(pathname: string): string | null {
  const match = pathname.match(/\/organizations\/([^/]+)/)
  return match?.[1] || null
}

/**
 * Extracts the current service/section from the URL
 * e.g., /projects/:id/storage -> "storage"
 */
function extractCurrentService(pathname: string): string | null {
  // Match patterns like /projects/:id/:service or /organizations/:id/:section
  const projectMatch = pathname.match(/\/projects\/[^/]+\/([^/]+)/)
  if (projectMatch) return projectMatch[1]

  const orgMatch = pathname.match(/\/organizations\/[^/]+\/([^/]+)/)
  if (orgMatch) return orgMatch[1]

  return null
}

/**
 * Provider component that sets Sentry context with user and navigation data.
 * This can be placed anywhere in the app - it gracefully handles missing auth.
 */
export function SentryContextProvider({
  children,
}: {
  children: React.ReactNode
}) {
  const location = useLocation()
  const { account, isAuthenticated } = useAuth()
  const accountUser = account as Models.User | undefined
  const { features } = useConsoleProfile()

  // Determine current org ID: URL (org pages) > project's org (project pages only when loaded) > user prefs
  const projectId = extractProjectId(location.pathname)
  const { project } = useProject(projectId ?? undefined)
  const orgIdFromUrl = extractOrgId(location.pathname)
  const currentOrgId = projectId
    ? (project?.teamId ?? undefined)
    : (orgIdFromUrl ??
        (accountUser?.prefs?.organization as string | undefined))

  // Use shared plan query so we don't duplicate API calls (loader already fetches on project pages)
  const { data: orgPlan } = useQuery({
    ...organizationPlanQueryOptions(currentOrgId),
    enabled: !!currentOrgId && typeof window !== 'undefined' && features.billing,
  })

  const skipMarketing = isMarketingPage({ pathname: location.pathname })

  // Set user context when authenticated (no PII - only IDs and status flags)
  useEffect(() => {
    if (!isSentryEnabled() || skipMarketing) return
    let cancelled = false
    void import('@/lib/sentry/init-client').then(({ withSentryClient }) => {
      if (cancelled) return
      withSentryClient((Sentry) => {
        if (isAuthenticated && accountUser) {
          // Only set user ID - no email or name to protect privacy
          Sentry.setUser({
            id: accountUser.$id,
          })

          // Set additional user context (no PII)
          Sentry.setContext('user_details', {
            userId: accountUser.$id,
            status: accountUser.status,
            emailVerification: accountUser.emailVerification,
            phoneVerification: accountUser.phoneVerification,
            mfaEnabled: accountUser.mfa,
            preferredOrgId: accountUser.prefs?.organization,
          })
          return
        }
        Sentry.setUser(null)
        Sentry.setContext('user_details', null)
      })
    })
    return () => {
      cancelled = true
    }
  }, [isAuthenticated, accountUser, skipMarketing])

  // Set navigation context based on current route
  useEffect(() => {
    if (!isSentryEnabled() || skipMarketing) return
    const routeProjectId = extractProjectId(location.pathname)
    const orgId = extractOrgId(location.pathname)
    const service = extractCurrentService(location.pathname)
    const preferredOrgId = accountUser?.prefs?.organization
    let cancelled = false
    void import('@/lib/sentry/init-client').then(({ withSentryClient }) => {
      if (cancelled) return
      withSentryClient((Sentry) => {
        Sentry.setContext('navigation', {
          pathname: location.pathname,
          projectId: routeProjectId,
          orgId,
          service,
          fullUrl: location.href,
        })

        if (routeProjectId) {
          Sentry.setTag('project_id', routeProjectId)
        } else {
          Sentry.setTag('project_id', undefined)
        }

        if (orgId) {
          Sentry.setTag('org_id', orgId)
        } else if (preferredOrgId) {
          Sentry.setTag('org_id', preferredOrgId as string)
        } else {
          Sentry.setTag('org_id', undefined)
        }

        if (service) {
          Sentry.setTag('service', service)
        } else {
          Sentry.setTag('service', undefined)
        }
      })
    })
    return () => {
      cancelled = true
    }
  }, [location.pathname, location.href, accountUser, skipMarketing])

  // Set organization plan context
  useEffect(() => {
    if (!isSentryEnabled() || skipMarketing) return
    let cancelled = false
    void import('@/lib/sentry/init-client').then(({ withSentryClient }) => {
      if (cancelled) return
      withSentryClient((Sentry) => {
        if (orgPlan) {
          Sentry.setContext('organization_plan', {
            planId: orgPlan.$id,
            planName: orgPlan.name,
          })
          Sentry.setTag('billing_plan', orgPlan.name)
          return
        }
        Sentry.setContext('organization_plan', null)
        Sentry.setTag('billing_plan', undefined)
      })
    })
    return () => {
      cancelled = true
    }
  }, [orgPlan, skipMarketing])

  return <>{children}</>
}
