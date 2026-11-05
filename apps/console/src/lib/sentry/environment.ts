import { isLocalDevelopmentHost } from '@/lib/sentry/environment-shared'

/**
 * True when the app runs on localhost or Vite dev. Sentry must not report in this mode.
 */
export function isLocalDevelopmentRuntime(
  hostname: string = typeof window !== 'undefined'
    ? window.location.hostname
    : '',
): boolean {
  if (import.meta.env.DEV) return true
  return isLocalDevelopmentHost(hostname)
}

/**
 * Resolve the Sentry `environment` tag from where the app is actually running.
 *
 * The SDK defaults to `production` when unset, which mis-tags local/dev sessions
 * that share the production DSN (localhost:3000 events showed up as production).
 */
export function getSentryEnvironment(
  hostname: string = typeof window !== 'undefined'
    ? window.location.hostname
    : '',
): string {
  if (isLocalDevelopmentRuntime(hostname)) {
    return 'development'
  }

  const host = hostname.trim().toLowerCase()

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

  return import.meta.env.MODE || 'production'
}
