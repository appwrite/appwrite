import { useLocation } from '@tanstack/react-router'
import { useEffect, useRef, useState } from 'react'
import { AlertTriangle, RefreshCw, Home, Copy, Check } from 'lucide-react'
import { formatError } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'

export function ErrorComponent({
  error,
  info,
  reset,
}: {
  error: Error
  info?: { componentStack: string }
  reset: () => void
}) {
  const randomErrorId = useRef<string>(
    Math.random().toString(36).substring(2, 15),
  )
  const location = useLocation()
  const navigate = useNavigate()
  const formattedError = formatError(error, 'An unexpected error occurred.')

  const message = {
    type: 'NOTIFY_ERROR',
    data: {
      errorId: randomErrorId.current,
      href: location.href,
      errorMessage: error.message,
      errorStack: error.stack,
      errorCause: error.cause,
      errorComponentStack: info?.componentStack,
    },
  }

  // Every 2 seconds, notify parent that an error exists
  useEffect(() => {
    const interval = setInterval(() => {
      window.parent.postMessage(message)
    }, 2000)

    return () => clearInterval(interval)
  }, [])

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
      errorDetails.componentStack && `\nComponent Stack:\n${errorDetails.componentStack}`,
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
    <div className="flex-grow flex flex-col justify-center items-center gap-6 my-20 px-4">
      <div className="flex flex-col items-center gap-4 max-w-md w-full">
        <div className="rounded-full bg-destructive/10 p-3">
          <AlertTriangle className="h-8 w-8 text-destructive" />
        </div>

        <div className="space-y-2 text-center">
          <h1 className="text-2xl font-semibold">{formattedError.title}</h1>
          <p className="text-muted-foreground text-sm leading-relaxed">
            {formattedError.message}
          </p>
        </div>

        <div className="mt-2 relative w-full max-w-full rounded-lg border bg-card px-4 py-3">
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

        <div className="flex flex-col sm:flex-row gap-3 mt-4 w-full">
          <Button
            variant="outline"
            onClick={handleGoHome}
            className="flex-1"
          >
            <Home className="mr-1.5 h-4 w-4" />
            Go Home
          </Button>
          <Button
            onClick={handleRetry}
            className="flex-1"
          >
            <RefreshCw className="mr-1.5 h-4 w-4" />
            Try Again
          </Button>
        </div>
      </div>
    </div>
  )
}
