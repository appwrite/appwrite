export const MARKETING_SITE_ORIGIN = 'https://appwrite.io'

export type MarketingPagePath =
  | '/terms'
  | '/privacy'
  | '/cookies'
  | '/company'
  | '/assets'
  | '/pricing'
  | '/partners'
  | '/education'
  | '/startups'
  | '/enterprise'
  | '/community'
  | '/docs'
  | '/changelog'
  | '/blog'
  | '/threads'
  | '/domains'
  | '/integrations'
  | '/home'

/**
 * Resolves a marketing page path to a relative route when marketing is enabled,
 * or to the production appwrite.io URL when marketing routes are disabled.
 */
export function getMarketingPageUrl(
  path: MarketingPagePath,
  marketingEnabled: boolean,
): string {
  return marketingEnabled ? path : `${MARKETING_SITE_ORIGIN}${path}`
}

export function isMarketingPageExternal(marketingEnabled: boolean): boolean {
  return !marketingEnabled
}

function normalizeBlogPath(path: string): string {
  return path.split('#')[0]?.replace(/\/+$/, '') || '/blog'
}

/** Returns a `/blog…` path when `href` points at the Appwrite blog, otherwise null. */
export function parseBlogPagePath(href: string): string | null {
  const trimmed = href.trim()

  if (trimmed.startsWith('/blog')) {
    return normalizeBlogPath(trimmed)
  }

  const match = trimmed.match(
    /^https?:\/\/(?:www\.)?appwrite\.io(\/blog(?:\/.*)?)?(?:[?#].*)?$/i,
  )
  if (!match) return null

  const path = match[1] ?? '/blog'
  return normalizeBlogPath(path)
}

export function isBlogPagePath(path: string): boolean {
  const blogPath = parseBlogPagePath(path)
  return blogPath === '/blog' || (blogPath?.startsWith('/blog/') ?? false)
}

/**
 * Resolves a blog path (or appwrite.io/blog URL) to a relative route when marketing
 * is enabled, or to the production appwrite.io URL when marketing routes are disabled.
 */
export function getBlogPageUrl(path: string, marketingEnabled: boolean): string {
  const blogPath = parseBlogPagePath(path)
  if (!blogPath) return path

  return marketingEnabled ? blogPath : `${MARKETING_SITE_ORIGIN}${blogPath}`
}

export function isBlogPageExternal(marketingEnabled: boolean): boolean {
  return !marketingEnabled
}

function normalizeDocsPath(path: string): string {
  const withoutHash = path.split('#')[0] ?? '/docs'
  const normalized = withoutHash.replace(/\/+$/, '') || '/docs'
  return normalized === '' ? '/docs' : normalized
}

/** Returns a `/docs…` path when `href` points at Appwrite docs, otherwise null. */
export function parseDocsPagePath(href: string): string | null {
  const trimmed = href.trim()

  if (trimmed.startsWith('/docs')) {
    return normalizeDocsPath(trimmed)
  }

  const match = trimmed.match(
    /^https?:\/\/(?:www\.)?appwrite\.io(\/docs(?:\/.*)?)?(?:[?#].*)?$/i,
  )
  if (!match) return null

  const path = match[1] ?? '/docs'
  return normalizeDocsPath(path)
}

/**
 * Resolves a docs path (or appwrite.io/docs URL) to a relative route when marketing
 * is enabled, or to the production appwrite.io URL when marketing routes are disabled.
 */
export function getDocsPageUrl(path: string, marketingEnabled: boolean): string {
  const docsPath = parseDocsPagePath(path)
  if (!docsPath) return path

  return marketingEnabled ? docsPath : `${MARKETING_SITE_ORIGIN}${docsPath}`
}
