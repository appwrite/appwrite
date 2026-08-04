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
  const host = hostname.trim().toLowerCase()

  if (
    import.meta.env.DEV ||
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

  return import.meta.env.MODE || 'production'
}
