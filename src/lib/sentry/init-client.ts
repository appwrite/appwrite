import * as Sentry from '@sentry/tanstackstart-react'
import { getRuntimeConfig } from '@/lib/runtime-config'
import { shouldSkipSentryError } from '@/lib/sentry/report-error'

let sentryInitialized = false

export function initSentryClient() {
  if (sentryInitialized || typeof window === 'undefined') return
  const sentryDsn = getRuntimeConfig().sentryDsn
  if (!sentryDsn) return

  Sentry.init({
    dsn: sentryDsn,
    sendDefaultPii: false,
    beforeSend(event, hint) {
      if (shouldSkipSentryError(hint.originalException)) return null
      return event
    },
  })
  sentryInitialized = true
}
