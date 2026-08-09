import * as Sentry from '@sentry/tanstackstart-react'
import { canTrackAnalytics } from '@/lib/cookie-consent/consent-state'
import { getRuntimeConfig } from '@/lib/runtime-config'
import { initSentryClient } from '@/lib/sentry/init-client'
import { shouldSkipSentryError } from '@/lib/sentry/skip-error'

export { shouldSkipSentryError } from '@/lib/sentry/skip-error'

/** Avoid duplicate Sentry events when onCatch and ErrorComponent both report. */
const reportedErrors = new WeakSet<object>()

export function isSentryReportingEnabled(): boolean {
  return !!getRuntimeConfig().sentryDsn && canTrackAnalytics()
}

/**
 * Extracts resource IDs from a URL pathname for Sentry tags/extra.
 */
export function extractRouteContext(pathname: string): {
  projectId?: string
  orgId?: string
  functionId?: string
  bucketId?: string
  fileId?: string
  databaseId?: string
  collectionId?: string
  documentId?: string
  userId?: string
  siteId?: string
  deploymentId?: string
  providerId?: string
  topicId?: string
  messageId?: string
  service?: string
} {
  const context: Record<string, string> = {}

  const projectMatch = pathname.match(/\/projects\/([^/]+)/)
  if (projectMatch) context.projectId = projectMatch[1]

  const orgMatch = pathname.match(/\/organizations\/([^/]+)/)
  if (orgMatch) context.orgId = orgMatch[1]

  const functionMatch = pathname.match(/\/functions\/([^/]+)/)
  if (functionMatch && functionMatch[1] !== 'executions') {
    context.functionId = functionMatch[1]
  }

  const bucketMatch = pathname.match(/\/storage\/([^/]+)/)
  if (bucketMatch && bucketMatch[1] !== 'files') {
    context.bucketId = bucketMatch[1]
  }

  const fileMatch = pathname.match(/\/files\/([^/]+)/)
  if (fileMatch) context.fileId = fileMatch[1]

  const dbMatch = pathname.match(/\/databases\/([^/]+)/)
  if (dbMatch) context.databaseId = dbMatch[1]

  const collectionMatch = pathname.match(/\/collections\/([^/]+)/)
  if (collectionMatch) context.collectionId = collectionMatch[1]

  const documentMatch = pathname.match(/\/documents\/([^/]+)/)
  if (documentMatch) context.documentId = documentMatch[1]

  const authSegmentMatch = pathname.match(/\/auth\/([^/]+)/)
  const authSegment = authSegmentMatch?.[1]
  const authTabSegments = new Set([
    'teams',
    'policies',
    'social-providers',
    'templates',
    'settings',
    'security',
    'users',
  ])
  if (authSegment && !authTabSegments.has(authSegment)) {
    context.userId = authSegment
  }

  const siteMatch = pathname.match(/\/sites\/([^/]+)/)
  if (siteMatch) context.siteId = siteMatch[1]

  const deploymentMatch = pathname.match(/\/deployments\/([^/]+)/)
  if (deploymentMatch) context.deploymentId = deploymentMatch[1]

  const providerMatch = pathname.match(/\/providers\/([^/]+)/)
  if (providerMatch) context.providerId = providerMatch[1]

  const topicMatch = pathname.match(/\/topics\/([^/]+)/)
  if (topicMatch) context.topicId = topicMatch[1]

  const messageMatch = pathname.match(/\/messages\/([^/]+)/)
  if (messageMatch) context.messageId = messageMatch[1]

  const serviceMatch = pathname.match(/\/projects\/[^/]+\/([^/]+)/)
  if (serviceMatch) context.service = serviceMatch[1]

  return context
}

export function toReportableError(error: unknown): Error {
  if (error instanceof Error) return error
  if (typeof error === 'string') return new Error(error)
  try {
    return new Error(JSON.stringify(error))
  } catch {
    return new Error(String(error))
  }
}

export type CaptureExceptionContext = {
  projectId?: string
  orgId?: string
  functionId?: string
  bucketId?: string
  databaseId?: string
  userId?: string
  siteId?: string
  componentStack?: string
  source?: string
  [key: string]: unknown
}

/**
 * Capture an exception with route/resource context.
 * Dedupes by error object identity so onCatch + ErrorComponent do not double-send.
 */
export function captureExceptionWithContext(
  error: unknown,
  additionalContext?: CaptureExceptionContext,
): string | undefined {
  if (!isSentryReportingEnabled()) return undefined
  if (shouldSkipSentryError(error)) return undefined
  if (!initSentryClient()) return undefined

  const dedupeKey =
    error !== null && typeof error === 'object' ? error : toReportableError(error)
  if (reportedErrors.has(dedupeKey)) return undefined
  reportedErrors.add(dedupeKey)

  const reportable = toReportableError(error)

  return (
    Sentry.captureException(reportable, {
      extra: {
        ...additionalContext,
        timestamp: new Date().toISOString(),
      },
      tags: {
        ...(additionalContext?.source && {
          error_source: additionalContext.source,
        }),
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
    }) ?? Sentry.lastEventId()
  )
}

/**
 * Report an error caught by a TanStack Router CatchBoundary (`onCatch` / `defaultOnCatch`).
 * Prefer this over waiting for the error UI to mount - the UI can fail to render.
 */
export function reportRouterCaughtError(
  error: Error,
  errorInfo?: { componentStack?: string | null },
  options?: { source?: string; pathname?: string; href?: string },
): string | undefined {
  const pathname =
    options?.pathname ??
    (typeof window !== 'undefined' ? window.location.pathname : '')
  const href =
    options?.href ?? (typeof window !== 'undefined' ? window.location.href : undefined)

  return captureExceptionWithContext(error, {
    ...extractRouteContext(pathname),
    componentStack: errorInfo?.componentStack ?? undefined,
    source: options?.source ?? 'router-onCatch',
    url: href,
    pathname,
  })
}

/**
 * Report window-level errors that never hit a React error boundary.
 */
export function reportUnhandledError(
  error: unknown,
  source: 'window.error' | 'unhandledrejection',
): string | undefined {
  if (shouldSkipSentryError(error)) return undefined

  const pathname =
    typeof window !== 'undefined' ? window.location.pathname : ''
  const href = typeof window !== 'undefined' ? window.location.href : undefined

  return captureExceptionWithContext(error, {
    ...extractRouteContext(pathname),
    source,
    url: href,
    pathname,
  })
}
