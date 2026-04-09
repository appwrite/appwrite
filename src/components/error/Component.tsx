import { useLocation } from '@tanstack/react-router'
import { useEffect, useMemo, useRef, useState } from 'react'
import { AlertTriangle, RefreshCw, Home, Copy, Check } from 'lucide-react'
import { captureExceptionWithContext } from '@/components/global/providers/SentryContext'
import { formatError } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'

/**
 * Extracts resource IDs from URL pathname for error context
 */
function extractRouteContext(pathname: string): {
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

  // Extract project ID
  const projectMatch = pathname.match(/\/projects\/([^/]+)/)
  if (projectMatch) context.projectId = projectMatch[1]

  // Extract organization ID
  const orgMatch = pathname.match(/\/organizations\/([^/]+)/)
  if (orgMatch) context.orgId = orgMatch[1]

  // Extract function ID
  const functionMatch = pathname.match(/\/functions\/([^/]+)/)
  if (functionMatch && functionMatch[1] !== 'executions')
    context.functionId = functionMatch[1]

  // Extract bucket ID
  const bucketMatch = pathname.match(/\/storage\/([^/]+)/)
  if (bucketMatch && bucketMatch[1] !== 'files')
    context.bucketId = bucketMatch[1]

  // Extract file ID
  const fileMatch = pathname.match(/\/files\/([^/]+)/)
  if (fileMatch) context.fileId = fileMatch[1]

  // Extract database ID
  const dbMatch = pathname.match(/\/databases\/([^/]+)/)
  if (dbMatch) context.databaseId = dbMatch[1]

  // Extract collection ID
  const collectionMatch = pathname.match(/\/collections\/([^/]+)/)
  if (collectionMatch) context.collectionId = collectionMatch[1]

  // Extract document ID
  const documentMatch = pathname.match(/\/documents\/([^/]+)/)
  if (documentMatch) context.documentId = documentMatch[1]

  // Extract user ID (auth section)
  const userMatch = pathname.match(/\/auth\/([^/]+)/)
  if (userMatch && userMatch[1] !== 'settings' && userMatch[1] !== 'teams')
    context.userId = userMatch[1]

  // Extract site ID
  const siteMatch = pathname.match(/\/sites\/([^/]+)/)
  if (siteMatch) context.siteId = siteMatch[1]

  // Extract deployment ID
  const deploymentMatch = pathname.match(/\/deployments\/([^/]+)/)
  if (deploymentMatch) context.deploymentId = deploymentMatch[1]

  // Extract messaging provider ID
  const providerMatch = pathname.match(/\/providers\/([^/]+)/)
  if (providerMatch) context.providerId = providerMatch[1]

  // Extract topic ID
  const topicMatch = pathname.match(/\/topics\/([^/]+)/)
  if (topicMatch) context.topicId = topicMatch[1]

  // Extract message ID
  const messageMatch = pathname.match(/\/messages\/([^/]+)/)
  if (messageMatch) context.messageId = messageMatch[1]

  // Extract current service
  const serviceMatch = pathname.match(/\/projects\/[^/]+\/([^/]+)/)
  if (serviceMatch) context.service = serviceMatch[1]

  return context
}

export function ErrorComponent({
  error,
  info,
  reset,
  preview = false,
}: {
  error: Error
  info?: { componentStack: string }
  reset: () => void
  /** When true, used for debug preview: skips Sentry and parent postMessage. */
  preview?: boolean
}) {
  const randomErrorId = useRef<string>(
    Math.random().toString(36).substring(2, 15),
  )
  const location = useLocation()
  const navigate = useNavigate()

  // Check if this is a project route and if the error is project-related
  const isProjectRoute = location.pathname.startsWith('/projects/')
  const errorMessage = error.message || ''
  const lowerMessage = errorMessage.toLowerCase()
  const errorWithCode = error as unknown as {
    code?: number
    status?: number
    type?: string
  }
  const errorCode = errorWithCode.code

  const isProjectNotFound =
    isProjectRoute &&
    (error.name === 'NotFoundError' ||
      errorCode === 404 ||
      lowerMessage.includes('not found') ||
      lowerMessage.includes('404') ||
      lowerMessage.includes('does not exist'))

  const isProjectAccessDenied =
    isProjectRoute &&
    (error.name === 'UnauthorizedError' ||
      error.name === 'ForbiddenError' ||
      errorCode === 401 ||
      errorCode === 403 ||
      lowerMessage.includes('unauthorized') ||
      lowerMessage.includes('forbidden') ||
      lowerMessage.includes('permission denied') ||
      lowerMessage.includes('access denied'))

  // Use project-specific messages for project routes
  const formattedError = isProjectNotFound
    ? {
        title: 'Project Not Found',
        message:
          'This project could not be found or you do not have access to view it.',
        isUserFriendly: true,
      }
    : isProjectAccessDenied
      ? {
          title: 'Access Denied',
          message:
            'You do not have permission to access this project. Please contact your administrator if you believe this is an error.',
          isUserFriendly: true,
        }
      : formatError(error, 'An unexpected error occurred.')

  const message = useMemo(
    () => ({
      type: 'NOTIFY_ERROR',
      data: {
        errorId: randomErrorId.current,
        href: location.href,
        errorMessage: error.message,
        errorStack: error.stack,
        errorCause: error.cause,
        errorComponentStack: info?.componentStack,
      },
    }),
    [
      location.href,
      error.message,
      error.stack,
      error.cause,
      info?.componentStack,
    ],
  )

  // Extract all available context from the current route
  const routeContext = extractRouteContext(location.pathname)

  // Capture error in Sentry with full context (no-op when VITE_SENTRY_DSN is not set; skipped in preview)
  // Skip 401 Unauthorized - we redirect to login and don't want these in Sentry
  const isUnauthorized = errorCode === 401 || errorWithCode.status === 401
  useEffect(() => {
    if (preview || isUnauthorized) return
    captureExceptionWithContext(error, {
      // Route-based context
      ...routeContext,
      // Error metadata
      errorId: randomErrorId.current,
      errorName: error.name,
      errorCode: errorWithCode.code,
      errorType: errorWithCode.type,
      // Component stack trace
      componentStack: info?.componentStack,
      // URL information
      url: location.href,
      pathname: location.pathname,
      // Error classification
      isProjectNotFound,
      isProjectAccessDenied,
      // Browser info
      userAgent:
        typeof navigator !== 'undefined' ? navigator.userAgent : undefined,
      language:
        typeof navigator !== 'undefined' ? navigator.language : undefined,
      // Screen info
      screenWidth:
        typeof window !== 'undefined' ? window.screen.width : undefined,
      screenHeight:
        typeof window !== 'undefined' ? window.screen.height : undefined,
      viewportWidth:
        typeof window !== 'undefined' ? window.innerWidth : undefined,
      viewportHeight:
        typeof window !== 'undefined' ? window.innerHeight : undefined,
    })
  }, [
    preview,
    isUnauthorized,
    error,
    info,
    location.href,
    location.pathname,
    isProjectNotFound,
    isProjectAccessDenied,
    routeContext,
  ])

  // Every 2 seconds, notify parent that an error exists (skipped in preview)
  useEffect(() => {
    if (preview) return
    const interval = setInterval(() => {
      window.parent.postMessage(message)
    }, 2000)

    return () => clearInterval(interval)
  }, [preview, message])

  const handleGoHome = () => {
    navigate({ to: '/' })
  }

  const handleRetry = () => {
    reset()
  }

  // Copy error details
  const [copied, setCopied] = useState(false)

  const handleCopy = async () => {
    const errorDetails = {
      message: error.message || 'No error message',
      stack: error.stack || 'No stack trace',
      cause: error.cause ? String(error.cause) : undefined,
      componentStack: info?.componentStack,
      url: location.href,
      errorId: randomErrorId.current,
    }

    const errorText = [
      `Error: ${errorDetails.message}`,
      errorDetails.stack && `\nStack:\n${errorDetails.stack}`,
      errorDetails.componentStack &&
        `\nComponent Stack:\n${errorDetails.componentStack}`,
      errorDetails.cause && `\nCause: ${errorDetails.cause}`,
      `\nURL: ${errorDetails.url}`,
      `Error ID: ${errorDetails.errorId}`,
    ]
      .filter(Boolean)
      .join('\n')

    await navigator.clipboard.writeText(errorText)
    setCopied(true)
    toast.success('Error details copied to clipboard')
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className="flex min-h-full w-full flex-col items-center justify-center gap-8 px-4 py-8">
      <div className="flex flex-col items-center max-w-md w-full gap-8">
        <div className="rounded-full bg-destructive/10 p-3">
          <AlertTriangle className="h-8 w-8 text-destructive" />
        </div>

        <div className="space-y-3 text-center">
          <h1 className="text-2xl font-semibold">{formattedError.title}</h1>
          <p className="text-muted-foreground text-sm leading-relaxed">
            {formattedError.message}
          </p>
        </div>

        {!(isProjectNotFound || isProjectAccessDenied) && (
          <div className="relative w-full max-w-full rounded-lg border bg-card px-4 py-3">
            <div className="flex items-start gap-2 pr-8 min-w-0 w-full">
              <AlertTriangle className="h-4 w-4 shrink-0 mt-0.5 text-destructive" />
              <div
                className="text-xs font-mono text-muted-foreground flex-1 min-w-0 overflow-hidden text-left"
                style={{ wordBreak: 'break-all', overflowWrap: 'break-word' }}
              >
                {error.message || 'No error details available'}
              </div>
            </div>
            <Button
              variant="ghost"
              size="sm"
              className="absolute top-2 right-2 h-7 w-7 p-0 shrink-0"
              onClick={handleCopy}
              aria-label="Copy error details"
            >
              {copied ? (
                <Check className="h-4 w-4 text-emerald-500" />
              ) : (
                <Copy className="h-4 w-4" />
              )}
            </Button>
          </div>
        )}

        {!(isProjectNotFound || isProjectAccessDenied) && (
          <div className="w-full border-t border-border pt-6">
            <p className="text-muted-foreground text-[13px] leading-relaxed text-center">
              We’ve already logged it to our error system and will probably spin
              up a super agent any minute to hunt this bug down. If you think
              this might be more than a client-side hiccup, check our{' '}
              <a
                href="https://status.appwrite.online"
                target="_blank"
                rel="noopener noreferrer"
                className="underline hover:text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 rounded"
              >
                status page
              </a>
              . Until then - try again or head home. You’ve got this.
            </p>
          </div>
        )}

        <div className="flex flex-col sm:flex-row gap-3 w-full">
          <Button
            variant="outline"
            onClick={handleGoHome}
            className="w-full shrink-0 sm:flex-1 min-h-9"
          >
            <Home className="mr-1.5 h-4 w-4" />
            Go Home
          </Button>
          <Button
            onClick={handleRetry}
            size="sm"
            className="h-9 min-h-9 w-full shrink-0 gap-2 text-[13px] font-medium text-white hover:opacity-90 sm:flex-1"
            style={{ backgroundColor: '#f02e65' }}
          >
            <RefreshCw className="h-4 w-4" />
            Try Again
          </Button>
        </div>
      </div>
    </div>
  )
}
