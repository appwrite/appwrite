import { useEffect } from 'react'
import * as Sentry from '@sentry/tanstackstart-react'
import { useLocation } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'

const isSentryEnabled = () => !!import.meta.env.VITE_SENTRY_DSN

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

  // Fetch account data directly - don't use useAuth to avoid circular dependencies
  // This query will fail gracefully if not authenticated
  const { data: account } = useQuery({
    queryKey: ['account', 'console', 'sentry'],
    queryFn: async () => {
      try {
        return await sdk.forConsole.account.get()
      } catch {
        return null
      }
    },
    retry: false,
    staleTime: 5 * 60 * 1000,
    enabled: typeof window !== 'undefined',
  })

  const isAuthenticated = !!account

  // Determine current org ID from URL or user prefs
  const orgIdFromUrl = extractOrgId(location.pathname)
  const currentOrgId =
    orgIdFromUrl || (account?.prefs?.organization as string | undefined)

  // Fetch organization plan for context
  const { data: orgPlan } = useQuery({
    queryKey: ['organization', 'plan', currentOrgId, 'sentry'],
    queryFn: async () => {
      try {
        if (!currentOrgId) return null
        return await sdk.forConsole.organizations.getPlan(currentOrgId)
      } catch {
        return null
      }
    },
    retry: false,
    staleTime: 5 * 60 * 1000,
    enabled: !!currentOrgId && typeof window !== 'undefined',
  })

  // Set user context when authenticated (no PII - only IDs and status flags)
  useEffect(() => {
    if (!isSentryEnabled()) return
    if (isAuthenticated && account) {
      // Only set user ID - no email or name to protect privacy
      Sentry.setUser({
        id: account.$id,
      })

      // Set additional user context (no PII)
      Sentry.setContext('user_details', {
        userId: account.$id,
        status: account.status,
        emailVerification: account.emailVerification,
        phoneVerification: account.phoneVerification,
        mfaEnabled: account.mfa,
        // Current organization from prefs
        preferredOrgId: account.prefs?.organization,
      })
    } else {
      // Clear user context when logged out
      Sentry.setUser(null)
      Sentry.setContext('user_details', null)
    }
  }, [isAuthenticated, account])

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
      if (account?.prefs?.organization) {
        Sentry.setTag('org_id', account.prefs.organization as string)
      } else {
        Sentry.setTag('org_id', undefined)
      }
    }

    if (service) {
      Sentry.setTag('service', service)
    } else {
      Sentry.setTag('service', undefined)
    }
  }, [location.pathname, location.href, account])

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
