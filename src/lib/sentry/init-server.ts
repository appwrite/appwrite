/**
 * Bun production-server Sentry instrumentation.
 *
 * Loaded raw by Bun (not Vite-bundled). Must stay free of Vite-only constructs
 * (`import.meta.env`, `?url` imports, path aliases).
 *
 * Preload this module before `server.ts` so Sentry is initialized before any
 * other imports run (and can capture boot-time module resolution failures):
 *
 *   bun --preload ./src/lib/sentry/init-server.ts run server.ts
 *
 * Server errors are operational monitoring and are NOT gated by cookie consent
 * (unlike the browser SDK).
 */
import * as Sentry from '@sentry/bun'
import { readRuntimeConfigFromEnv } from '../runtime-config-shared.ts'

let initialized = false

function getServerSentryEnvironment(): string {
  const explicit = process.env.SENTRY_ENVIRONMENT?.trim()
  if (explicit) return explicit

  const siteOrigin = process.env.VITE_SITE_ORIGIN?.trim()
  if (siteOrigin) {
    try {
      const host = new URL(siteOrigin).hostname.toLowerCase()
      if (
        host === 'localhost' ||
        host === '127.0.0.1' ||
        host === '[::1]' ||
        host === '::1' ||
        host.endsWith('.local')
      ) {
        return 'development'
      }
      if (
        host === 'new.appwrite.io' ||
        host === 'cloud.appwrite.io' ||
        host === 'appwrite.io' ||
        host === 'www.appwrite.io'
      ) {
        return 'production'
      }
      if (host.includes('staging') || host.includes('stage')) {
        return 'staging'
      }
      if (host.endsWith('.vercel.app') || host.includes('preview')) {
        return 'preview'
      }
    } catch {
      // Fall through to NODE_ENV.
    }
  }

  if (process.env.NODE_ENV === 'development') return 'development'
  if (process.env.NODE_ENV === 'test') return 'test'
  return 'production'
}

function getServerSentryDsn(): string {
  return readRuntimeConfigFromEnv(process.env).sentryDsn
}

export function isServerSentryEnabled(): boolean {
  return Boolean(getServerSentryDsn())
}

/**
 * Initialize the Bun Sentry SDK. Safe to call multiple times.
 * Returns true when a DSN was present and init ran (or already ran).
 */
export function initSentryServer(): boolean {
  if (initialized) return isServerSentryEnabled()
  initialized = true

  const dsn = getServerSentryDsn()
  if (!dsn) return false

  Sentry.init({
    dsn,
    environment: getServerSentryEnvironment(),
    sendDefaultPii: false,
    // Errors only for now; match the client SDK's lack of tracing.
    tracesSampleRate: 0,
    initialScope: {
      tags: {
        runtime: 'bun',
        server: 'true',
      },
    },
  })

  return true
}

function toError(error: unknown): Error {
  if (error instanceof Error) return error
  if (typeof error === 'string') return new Error(error)
  try {
    return new Error(JSON.stringify(error))
  } catch {
    return new Error(String(error))
  }
}

export type ServerCaptureContext = {
  source?: string
  [key: string]: unknown
}

/** Capture a server-side exception. No-op when Sentry is not configured. */
export function captureServerException(
  error: unknown,
  context: ServerCaptureContext = {},
): void {
  if (!initSentryServer()) return

  const { source, ...extra } = context
  Sentry.captureException(toError(error), {
    tags: {
      ...(source ? { error_source: source } : {}),
    },
    extra: {
      ...extra,
      timestamp: new Date().toISOString(),
    },
  })
}

/** Flush pending events before process exit. */
export async function flushSentryServer(
  timeoutMs: number = 2000,
): Promise<void> {
  if (!initialized || !isServerSentryEnabled()) return
  await Sentry.flush(timeoutMs)
}

// Side effect for `bun --preload`: init before any other app modules load.
initSentryServer()
