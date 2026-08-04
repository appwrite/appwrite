export const SEO_INDEXABLE_HOSTS = ['appwrite.io', 'www.appwrite.io'] as const

/** Set to false to allow indexing on all hosts (no noindex headers or blocking robots.txt). */
export const BLOCK_NON_PRODUCTION_SEO = false

export const NOINDEX_ROBOTS_META = {
  name: 'robots',
  content: 'noindex, nofollow',
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
