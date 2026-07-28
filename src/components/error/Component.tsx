import {
  useCanGoBack,
  useLocation,
  useNavigate,
  useRouter,
} from '@tanstack/react-router'
import { useEffect, useMemo, useRef, useState } from 'react'
import {
  AlertTriangle,
  ArrowLeft,
  RefreshCw,
  Home,
  Copy,
  Check,
  WifiOff,
} from 'lucide-react'
import { captureExceptionWithContext } from '@/components/global/providers/SentryContext'
import { extractRouteContext } from '@/lib/sentry/report-error'
import { formatError } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { toast } from 'sonner'
import { useConfirmedOffline } from '@/lib/network-connectivity'
import {
  forceReloadForStaleChunk,
  isStaleChunkLoadError,
  tryReloadForStaleChunk,
} from '@/lib/stale-chunk-error'
import { useT } from '@/lib/i18n/translate'

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
  const t = useT()
  const randomErrorId = useRef<string>(
    Math.random().toString(36).substring(2, 15),
  )
  const location = useLocation()
  const navigate = useNavigate()
  const router = useRouter()
  const canGoBack = useCanGoBack()
  const isConfirmedOffline = useConfirmedOffline()

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

  // Only use the offline-specific UI when offline is confirmed (not just
  // navigator.onLine, which is unreliable). Other failures show the regular page.
  const isConnectivityError = isConfirmedOffline
  const isStaleChunkError = isStaleChunkLoadError(error)

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
      : isConnectivityError
        ? {
            title: "You're offline",
            message:
              'This page needs a connection to Appwrite. Reconnect to the internet, then try again - we can reload automatically when you are back online.',
            isUserFriendly: true,
          }
        : isStaleChunkError
          ? {
              title: 'Update available',
              message:
                'A newer version of the console was deployed while you had this tab open. Reload the page to continue.',
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
  const routeContext = useMemo(
    () => extractRouteContext(location.pathname),
    [location.pathname],
  )

  // Capture error in Sentry with full context (no-op when VITE_SENTRY_DSN is not set; skipped in preview).
  // Deduped if router onCatch already reported the same error object.
  // Skip 401 Unauthorized - we redirect to login and don't want these in Sentry
  const isUnauthorized = errorCode === 401 || errorWithCode.status === 401
  useEffect(() => {
    if (preview || isUnauthorized || isConnectivityError || isStaleChunkError)
      return
    captureExceptionWithContext(error, {
      // Route-based context
      ...routeContext,
      source: 'error-component',
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
      isConnectivityError,
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
    isConnectivityError,
    isStaleChunkError,
    routeContext,
  ])

  useEffect(() => {
    if (preview || !isStaleChunkError) return
    tryReloadForStaleChunk(error)
  }, [preview, isStaleChunkError, error])

  useEffect(() => {
    if (!isConnectivityError || preview) return
    const onOnline = () => {
      reset()
    }
    window.addEventListener('online', onOnline)
    return () => window.removeEventListener('online', onOnline)
  }, [isConnectivityError, preview, reset])

  // Every 2 seconds, notify parent that an error exists (skipped in preview)
  useEffect(() => {
    if (preview || isConnectivityError) return
    const interval = setInterval(() => {
      window.parent.postMessage(message)
    }, 2000)

    return () => clearInterval(interval)
  }, [preview, message, isConnectivityError])

  const handleGoHome = () => {
    navigate({ to: '/' })
  }

  const handleGoBack = () => {
    router.history.back()
  }

  const handleRetry = () => {
    reset()
  }

  const handleReload = () => {
    // Cache-bust so we don't re-serve HTML that still references deleted chunks.
    forceReloadForStaleChunk()
  }

  const showTechnicalDetails =
    !isProjectNotFound &&
    !isProjectAccessDenied &&
    !isConnectivityError &&
    !isStaleChunkError
  const showSupportBlurb =
    !isProjectNotFound &&
    !isProjectAccessDenied &&
    !isConnectivityError &&
    !isStaleChunkError

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
    toast.success(t('Error details copied to clipboard'))
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className="flex min-h-full w-full flex-col items-center justify-center gap-8 px-4 py-8">
      <div className="flex flex-col items-center max-w-md w-full gap-8">
        <div
          className={cn(
            'rounded-full p-3',
            isConnectivityError
              ? 'bg-amber-500/15'
              : 'bg-destructive/10',
          )}
        >
          {isConnectivityError ? (
            <WifiOff className="h-8 w-8 text-amber-600 dark:text-amber-400" />
          ) : (
            <AlertTriangle className="h-8 w-8 text-destructive" />
          )}
        </div>

        <div className="space-y-3 text-center">
          <h1 className="text-2xl font-semibold">{t(formattedError.title)}</h1>
          <p className="text-muted-foreground text-sm leading-relaxed">
            {t(formattedError.message)}
          </p>
        </div>

        {isConnectivityError ? (
          <p className="text-muted-foreground text-[13px] leading-relaxed text-center -mt-4">
            {t('If your connection looks fine, check our')}{' '}
            <a
              href="https://status.appwrite.online"
              target="_blank"
              rel="noopener noreferrer"
              className="underline hover:text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 rounded"
            >
              {t('status page')}
            </a>{' '}
            {t('for service updates.')}
          </p>
        ) : null}

        {showTechnicalDetails ? (
          <div className="relative w-full max-w-full rounded-lg border bg-card px-4 py-3">
            <div className="flex items-start gap-2 pe-8 min-w-0 w-full">
              <AlertTriangle className="h-4 w-4 shrink-0 mt-0.5 text-destructive" />
              <div
                className="text-xs font-mono text-muted-foreground flex-1 min-w-0 overflow-hidden text-start"
                style={{ wordBreak: 'break-all', overflowWrap: 'break-word' }}
              >
                {error.message || t('No error details available')}
              </div>
            </div>
            <Button
              variant="ghost"
              size="sm"
              className="absolute top-2 end-2 h-7 w-7 p-0 shrink-0"
              onClick={handleCopy}
              aria-label={t('Copy error details')}
            >
              {copied ? (
                <Check className="h-4 w-4 text-emerald-500" />
              ) : (
                <Copy className="h-4 w-4" />
              )}
            </Button>
          </div>
        ) : null}

        {showSupportBlurb ? (
          <div className="w-full border-t border-border pt-6">
            <p className="text-muted-foreground text-[13px] leading-relaxed text-center">
              {t(
                'We’ve already logged it to our error system and will probably spin up a super agent any minute to hunt this bug down. If you think this might be more than a client-side hiccup, check our',
              )}{' '}
              <a
                href="https://status.appwrite.online"
                target="_blank"
                rel="noopener noreferrer"
                className="underline hover:text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 rounded"
              >
                {t('status page')}
              </a>
              . {t('Until then - try again or head home. You’ve got this.')}
            </p>
          </div>
        ) : null}

        {isStaleChunkError ? (
          <div className="flex w-full flex-col gap-3">
            <Button
              variant="brandCta"
              onClick={handleReload}
              size="sm"
              className="h-9 min-h-9 w-full shrink-0 gap-2 text-[13px] font-medium"
            >
              <RefreshCw className="h-4 w-4" />
              {t('Reload page')}
            </Button>
          </div>
        ) : isConnectivityError ? (
          <div className="flex w-full flex-col gap-3">
            <Button
              variant="brandCta"
              onClick={handleRetry}
              size="sm"
              className="h-9 min-h-9 w-full shrink-0 gap-2 text-[13px] font-medium"
            >
              <RefreshCw className="h-4 w-4" />
              {t('Try again')}
            </Button>
            <div className="flex flex-col gap-3 sm:flex-row">
              {canGoBack ? (
                <Button
                  variant="outline"
                  onClick={handleGoBack}
                  className="w-full shrink-0 sm:flex-1 min-h-9"
                >
                  <ArrowLeft className="me-1.5 h-4 w-4" />
                  {t('Go back')}
                </Button>
              ) : null}
              <Button
                variant="outline"
                onClick={handleGoHome}
                className={cn(
                  'w-full shrink-0 min-h-9 sm:flex-1',
                  !canGoBack && 'sm:w-full',
                )}
              >
                <Home className="me-1.5 h-4 w-4" />
                {t('Go home')}
              </Button>
            </div>
          </div>
        ) : (
          <div className="flex flex-col sm:flex-row gap-3 w-full">
            <Button
              variant="outline"
              onClick={handleGoHome}
              className="w-full shrink-0 sm:flex-1 min-h-9"
            >
              <Home className="me-1.5 h-4 w-4" />
              {t('Go home')}
            </Button>
            <Button
              variant="brandCta"
              onClick={handleRetry}
              size="sm"
              className="h-9 min-h-9 w-full shrink-0 gap-2 text-[13px] font-medium sm:flex-1"
            >
              <RefreshCw className="h-4 w-4" />
              {t('Try again')}
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}
