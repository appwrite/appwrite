/**
 * Hostname checks shared by the Vite client and the raw Bun server preload.
 * Must stay free of Vite-only constructs (`import.meta.env`, path aliases).
 */

export function isLocalDevelopmentHost(hostname: string): boolean {
  const host = hostname.trim().toLowerCase()
  return (
    host === 'localhost' ||
    host === '127.0.0.1' ||
    host === '[::1]' ||
    host === '::1' ||
    host.endsWith('.local')
  )
}

export function isLocalDevelopmentFromSiteOrigin(
  siteOrigin: string | undefined,
): boolean {
  const value = siteOrigin?.trim()
  if (!value) return false
  try {
    return isLocalDevelopmentHost(new URL(value).hostname)
  } catch {
    return false
  }
}

/** Bun server context: true when this process is running a local dev deployment. */
export function isLocalDevelopmentServerEnv(
  env: Record<string, string | undefined> = process.env,
): boolean {
  if (env.NODE_ENV === 'development' || env.NODE_ENV === 'test') return true
  if (env.MODE === 'development') return true
  return isLocalDevelopmentFromSiteOrigin(env.VITE_SITE_ORIGIN)
}
