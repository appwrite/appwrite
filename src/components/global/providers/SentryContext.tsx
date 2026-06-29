import { useEffect } from 'react'
import * as Sentry from '@sentry/tanstackstart-react'
import { useLocation } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useProject } from '@/lib/react-query/hooks'
import { organizationPlanQueryOptions } from '@/lib/react-query/hooks/organizations'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getRuntimeConfig } from '@/lib/runtime-config'
import { canTrackAnalytics } from '@/lib/cookie-consent/consent-state'

const isSentryEnabled = () =>
  !!getRuntimeConfig().sentryDsn && canTrackAnalytics()

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

  // Set user context when authenticated (no PII - only IDs and status flags)
  useEffect(() => {
    if (!isSentryEnabled()) return
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
        // Current organization from prefs
        preferredOrgId: accountUser.prefs?.organization,
      })
    } else {
      // Clear user context when logged out
      Sentry.setUser(null)
      Sentry.setContext('user_details', null)
    }
  }, [isAuthenticated, accountUser])

  // Set navigation context based on current route
  useEffect(() => {
    if (!isSentryEnabled()) return
    const projectId = extractProjectId(location.pathname)
    const orgId = extractOrgId(location.pathname)
    const service = extractCurrentService(location.pathname)

    // Set route context
    Sentry.setContext('navigation', {
      pathname: location.pathname,
      projectId,
      orgId,
      service,
      fullUrl: location.href,
    })

    // Set tags for easy filtering in Sentry
    if (projectId) {
      Sentry.setTag('project_id', projectId)
    } else {
      Sentry.setTag('project_id', undefined)
    }

    if (orgId) {
      Sentry.setTag('org_id', orgId)
    } else {
      // Try to get org from user prefs if not in URL
      if (accountUser?.prefs?.organization) {
        Sentry.setTag('org_id', accountUser.prefs.organization as string)
      } else {
        Sentry.setTag('org_id', undefined)
      }
    }

    if (service) {
      Sentry.setTag('service', service)
    } else {
      Sentry.setTag('service', undefined)
    }
  }, [location.pathname, location.href, accountUser])

  // Set organization plan context
  useEffect(() => {
    if (!isSentryEnabled()) return
    if (orgPlan) {
      Sentry.setContext('organization_plan', {
        planId: orgPlan.$id,
        planName: orgPlan.name,
      })

      // Add plan as a tag for easy filtering
      Sentry.setTag('billing_plan', orgPlan.name)
    } else {
      Sentry.setContext('organization_plan', null)
      Sentry.setTag('billing_plan', undefined)
    }
  }, [orgPlan])

  return <>{children}</>
}

/**
 * Helper function to capture an exception with full context.
 * Use this for manual error capturing with rich context data.
 * @returns The Sentry event ID (trace ID) if available, otherwise undefined
 */
export function captureExceptionWithContext(
  error: Error,
  additionalContext?: {
    projectId?: string
    orgId?: string
    functionId?: string
    bucketId?: string
    databaseId?: string
    userId?: string
    siteId?: string
    componentStack?: string
    [key: string]: unknown
  },
): string | undefined {
  if (!isSentryEnabled()) return undefined
  Sentry.captureException(error, {
    extra: {
      ...additionalContext,
      timestamp: new Date().toISOString(),
    },
    tags: {
      ...(additionalContext?.projectId && {
        project_id: additionalContext.projectId,
      }),
      ...(additionalContext?.orgId && { org_id: additionalContext.orgId }),
      ...(additionalContext?.functionId && {
        function_id: additionalContext.functionId,
      }),
      ...(additionalContext?.bucketId && {
        bucket_id: additionalContext.bucketId,
      }),
      ...(additionalContext?.databaseId && {
        database_id: additionalContext.databaseId,
      }),
      ...(additionalContext?.siteId && { site_id: additionalContext.siteId }),
    },
  })

  // Get the event ID after capturing (Sentry.lastEventId() gets the last captured event)
  return Sentry.lastEventId()
}
