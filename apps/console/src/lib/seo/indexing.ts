export const SEO_INDEXABLE_HOSTS = ['appwrite.io'] as const

/** Apex host used for permanent subdomain redirects (www / new → apex). */
export const CANONICAL_HOST = 'appwrite.io'

/**
 * Production hosts that must 301 to {@link CANONICAL_HOST}, keeping path and query.
 */
export const HOSTS_REDIRECT_TO_CANONICAL = [
  'www.appwrite.io',
  'new.appwrite.io',
] as const

/**
 * Hosts that 301 to another host, keeping path and query.
 * Production aliases go to the apex. The staging alias stays on staging.
 */
const HOST_REDIRECT_TARGETS: Record<string, string> = {
  ...Object.fromEntries(
    HOSTS_REDIRECT_TO_CANONICAL.map((host) => [host, CANONICAL_HOST]),
  ),
  'new.staging.appwrite.io': 'staging.appwrite.io',
}

/** Set to false to allow indexing on all hosts (no noindex headers or blocking robots.txt). */
export const BLOCK_NON_PRODUCTION_SEO = true

export const NOINDEX_ROBOTS_META = {
  name: 'robots',
  content: 'noindex, nofollow',
} as const

/** Duplicate or alternate URLs that should stay crawlable but not rank. */
export const NOINDEX_FOLLOW_ROBOTS_META = {
  name: 'robots',
  content: 'noindex, follow',
} as const

/**
 * Default robots directive for indexable hosts. `max-image-preview:large`
 * makes pages eligible for large image previews in Google Search and is a
 * requirement for good Google Discover presentation.
 */
export const INDEXABLE_ROBOTS_META = {
  name: 'robots',
  content: 'max-image-preview:large',
} as const

export const NOINDEX_ROBOTS_HEADER = 'noindex, nofollow'

const SEO_INDEXABLE_HOST_SET = new Set<string>(SEO_INDEXABLE_HOSTS)

export function normalizeRequestHost(host: string): string {
  return host.trim().toLowerCase().split(':')[0] ?? ''
}

/** Target host for a permanent redirect, or null when this host should be served. */
export function getHostRedirectTarget(host: string): string | null {
  return HOST_REDIRECT_TARGETS[normalizeRequestHost(host)] ?? null
}

export function shouldRedirectToCanonicalHost(host: string): boolean {
  return getHostRedirectTarget(host) !== null
}

/**
 * Build a permanent redirect URL to `targetHost` with the same path and query.
 */
export function getCanonicalHostRedirectUrl(
  requestUrl: string,
  targetHost: string = CANONICAL_HOST,
): string {
  const incoming = new URL(requestUrl)
  return new URL(
    `${incoming.pathname}${incoming.search}`,
    `https://${targetHost}`,
  ).toString()
}

/** 301 to the mapped host, or null when this request should be served as-is. */
export function getCanonicalHostRedirectResponse(
  request: Request,
): Response | null {
  const host = getRequestHostFromHeaders(request.headers, request.url)
  const targetHost = getHostRedirectTarget(host)
  if (!targetHost) return null
  return Response.redirect(
    getCanonicalHostRedirectUrl(request.url, targetHost),
    301,
  )
}

export function isSeoIndexableHost(host: string): boolean {
  if (!BLOCK_NON_PRODUCTION_SEO) return true
  return SEO_INDEXABLE_HOST_SET.has(normalizeRequestHost(host))
}

export function isSeoIndexableOrigin(origin: string): boolean {
  try {
    return isSeoIndexableHost(new URL(origin).hostname)
  } catch {
    return false
  }
}

export function getRequestHostFromHeaders(
  headers: Headers,
  requestUrl: string,
): string {
  const forwarded = headers.get('x-forwarded-host')
  if (forwarded) {
    const first = forwarded.split(',')[0]?.trim()
    if (first) return normalizeRequestHost(first)
  }

  try {
    return normalizeRequestHost(new URL(requestUrl).host)
  } catch {
    return ''
  }
}

export function getSeoRobotsMetaTags(siteOrigin: string) {
  return isSeoIndexableOrigin(siteOrigin)
    ? [INDEXABLE_ROBOTS_META]
    : [NOINDEX_ROBOTS_META]
}

export function getNonProductionRobotsTxt(): string {
  return `# Non-production host. Do not index.
User-agent: *
Disallow: /
`
}

export function applyNoIndexResponseHeaders(headers: Headers): Headers {
  const next = new Headers(headers)
  next.set('X-Robots-Tag', NOINDEX_ROBOTS_HEADER)
  return next
}
