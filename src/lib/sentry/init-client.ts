import * as Sentry from '@sentry/tanstackstart-react'
import { getRuntimeConfig } from '@/lib/runtime-config'
import { getSentryEnvironment } from '@/lib/sentry/environment'
import { shouldSkipSentryError } from '@/lib/sentry/skip-error'

let sentryInitialized = false

/**
 * Initialize the browser Sentry client when a DSN is configured.
 * Idempotent: safe to call multiple times (e.g. after consent, HMR, debug test).
 */
export function initSentryClient(): boolean {
  if (typeof window === 'undefined') return false
  if (sentryInitialized) return !!Sentry.getClient()

  const sentryDsn = getRuntimeConfig().sentryDsn
  if (!sentryDsn) return false

  Sentry.init({
    dsn: sentryDsn,
    environment: getSentryEnvironment(),
    sendDefaultPii: false,
    beforeSend(event, hint) {
      if (shouldSkipSentryError(hint.originalException)) return null
      return event
    },
  })
  sentryInitialized = true
  return !!Sentry.getClient()
}

export function isSentryClientInitialized(): boolean {
  return sentryInitialized && !!Sentry.getClient()
}

export type SentryDebugTestResult =
  | { ok: true; eventId: string }
  | { ok: false; reason: string; eventId?: string }

/**
 * Force-init Sentry and send a test exception (bypasses cookie-consent gating).
 * Awaits flush so a network request should appear before this resolves.
 */
export async function sendSentryDebugTestError(): Promise<SentryDebugTestResult> {
  if (typeof window === 'undefined') {
    return { ok: false, reason: 'Not in a browser context' }
  }

  const sentryDsn = getRuntimeConfig().sentryDsn
  if (!sentryDsn) {
    return { ok: false, reason: 'VITE_SENTRY_DSN is not set in runtime config' }
  }

  if (!initSentryClient()) {
    return { ok: false, reason: 'Sentry client failed to initialize' }
  }

  const eventId = Sentry.captureException(
    new Error('Debug menu Sentry test error'),
    {
      tags: { error_source: 'debug-menu' },
      extra: {
        timestamp: new Date().toISOString(),
        environment: getSentryEnvironment(),
      },
    },
  )

  const resolvedId = eventId || Sentry.lastEventId()
  if (!resolvedId) {
    return {
      ok: false,
      reason: 'captureException did not produce an event id',
    }
  }

  const flushed = await Sentry.flush(5000)
  if (!flushed) {
    return {
      ok: false,
      eventId: resolvedId,
      reason:
        'Flush timed out. Check Network for ingest.us.sentry.io (ad blockers often block it).',
    }
  }

  return { ok: true, eventId: resolvedId }
}
