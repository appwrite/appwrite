import { AlertCircle, Info } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { isDomainClaimError } from '@/lib/utils/proxy-domains'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

export type DomainVerificationError = {
  title: string
  message: string
  /** DNS not ready yet - show amber guidance instead of a hard error */
  pending?: boolean
}

/** Shared alert when proxy-rule DNS is not confirmed yet. */
export function dnsPendingVerificationError(
  t: (text: string) => string,
): DomainVerificationError {
  return {
    title: t('Domain not verified yet'),
    message: t(
      'DNS changes can take up to 48 hours to propagate. Confirm the records below at your DNS provider, wait a bit, then try again.',
    ),
    pending: true,
  }
}

/**
 * Alert for a failed verify call. A failed DNS check or a refused ownership
 * claim keeps the amber pending style; the claim step's own copy and record
 * table explain what to add, so the API's message is not repeated. Anything
 * else is a hard failure.
 */
export function domainVerificationError(
  t: (text: string) => string,
  error: unknown,
): DomainVerificationError {
  if (isDomainClaimError(error)) {
    return dnsPendingVerificationError(t)
  }
  return {
    title: t('Verification failed'),
    message: getErrorMessage(error, t('Failed to verify domain')),
  }
}

/** Amber "not verified yet" or red failure alert above the DNS records. */
export function DomainVerificationAlert({
  error,
}: {
  error?: DomainVerificationError | string | null
}) {
  const t = useT()
  const structuredError = error && typeof error !== 'string' ? error : null
  const plainError = typeof error === 'string' ? error : null
  const errorPending = structuredError?.pending ?? false
  const errorTitle =
    structuredError?.title ?? (plainError ? t('Verification failed') : null)
  const errorMessage = structuredError?.message ?? plainError

  if (!errorMessage || !errorTitle) return null

  return (
    <Alert
      variant="default"
      className={
        errorPending
          ? 'border-amber-500/30 bg-amber-500/10 [&>svg]:text-amber-600 dark:[&>svg]:text-amber-400'
          : 'border-red-500/30 bg-red-500/10 [&>svg]:text-red-600 dark:[&>svg]:text-red-400'
      }
    >
      {errorPending ? (
        <Info className="h-4 w-4" />
      ) : (
        <AlertCircle className="h-4 w-4" />
      )}
      <AlertTitle
        className={
          errorPending
            ? 'text-[13px] font-medium text-amber-700 dark:text-amber-400'
            : 'text-[13px] font-medium text-red-600 dark:text-red-400'
        }
      >
        {errorTitle}
      </AlertTitle>
      <AlertDescription
        className={
          errorPending
            ? 'text-[13px] text-amber-700/90 dark:text-amber-400/90'
            : 'text-[13px] text-red-600 dark:text-red-400'
        }
      >
        {errorMessage}
      </AlertDescription>
    </Alert>
  )
}
