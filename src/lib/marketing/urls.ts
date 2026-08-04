import {
  MARKETING_PAGE_PATHS,
  normalizeMarketingPath,
} from '@/lib/marketing/marketing-page-paths'

export const MARKETING_SITE_ORIGIN = 'https://appwrite.io'

export type MarketingPagePath =
  | '/terms'
  | '/privacy'
  | '/cookies'
  | '/baa'
  | '/company'
  | '/assets'
  | '/pricing'
  | '/partners'
  | '/education'
  | '/startups'
  | '/enterprise'
  | '/affiliates'
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
  const withoutHash = path.split('#')[0]?.split('?')[0] ?? '/docs'
  const normalized = withoutHash.replace(/\/+$/, '') || '/docs'
  return normalized === '' ? '/docs' : normalized
}

/** Splits a path or URL into pathname and hash fragment (without `#`). */
export function splitHrefHash(href: string): { pathname: string; hash: string } {
  const trimmed = href.trim()
  const hashIndex = trimmed.indexOf('#')
  if (hashIndex < 0) return { pathname: trimmed, hash: '' }
  return {
    pathname: trimmed.slice(0, hashIndex),
    hash: trimmed.slice(hashIndex + 1),
  }
}

function appendHrefHash(url: string, hash: string): string {
  return hash ? `${url}#${hash}` : url
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
  const { pathname, hash } = splitHrefHash(path)
  const docsPath = parseDocsPagePath(pathname)
  if (!docsPath) return path

  const base = marketingEnabled ? docsPath : `${MARKETING_SITE_ORIGIN}${docsPath}`
  return appendHrefHash(base, hash)
}

export function isDocsPageExternal(marketingEnabled: boolean): boolean {
  return !marketingEnabled
}

export function getDocsPageUrlFromSlug(
  slug: string,
  marketingEnabled: boolean,
): string {
  const path = slug ? `/docs/${slug}` : '/docs'
  return getDocsPageUrl(path, marketingEnabled)
}

function normalizeProductPath(path: string): string {
  return path.split('#')[0]?.replace(/\/+$/, '') || ''
}

/** Returns a `/products…` path when `href` points at an Appwrite product page, otherwise null. */
export function parseProductPagePath(href: string): string | null {
  const trimmed = href.trim()

  if (trimmed.startsWith('/products/')) {
    return normalizeProductPath(trimmed)
  }

  const match = trimmed.match(
    /^https?:\/\/(?:www\.)?appwrite\.io(\/products\/[^?#]*)(?:[?#].*)?$/i,
  )
  if (!match?.[1]) return null

  return normalizeProductPath(match[1])
}

export function getProductPageUrl(path: string, marketingEnabled: boolean): string {
  const productPath = parseProductPagePath(path)
  if (!productPath) return path

  return marketingEnabled ? productPath : `${MARKETING_SITE_ORIGIN}${productPath}`
}

export function isProductPageExternal(marketingEnabled: boolean): boolean {
  return !marketingEnabled
}

/** Returns a known marketing site path (pricing, domains, etc.), otherwise null. */
export function parseMarketingSitePagePath(href: string): string | null {
  const trimmed = href.trim()
  let path: string | null = null

  if (trimmed.startsWith('/')) {
    path = normalizeMarketingPath(trimmed.split('#')[0] ?? trimmed)
  } else {
    const match = trimmed.match(/^https?:\/\/(?:www\.)?appwrite\.io(\/[^?#]*)(?:[?#].*)?$/i)
    path = match?.[1] ? normalizeMarketingPath(match[1]) : null
  }

  if (!path) return null
  return (MARKETING_PAGE_PATHS as readonly string[]).includes(path) ? path : null
}

/**
 * Resolves docs, blog, product, and marketing page links based on the active profile.
 */
export function resolveSiteLinkUrl(href: string, marketingEnabled: boolean): string {
  if (parseDocsPagePath(href)) return getDocsPageUrl(href, marketingEnabled)

  const blogPath = parseBlogPagePath(href)
  if (blogPath) return getBlogPageUrl(blogPath, marketingEnabled)

  const productPath = parseProductPagePath(href)
  if (productPath) return getProductPageUrl(productPath, marketingEnabled)

  const marketingPath = parseMarketingSitePagePath(href)
  if (marketingPath) {
    return marketingEnabled
      ? marketingPath
      : `${MARKETING_SITE_ORIGIN}${marketingPath}`
  }

  return href
}

export function isSiteLinkExternal(href: string, marketingEnabled: boolean): boolean {
  if (parseDocsPagePath(href)) return isDocsPageExternal(marketingEnabled)
  if (parseBlogPagePath(href)) return isBlogPageExternal(marketingEnabled)
  if (parseProductPagePath(href)) return isProductPageExternal(marketingEnabled)
  if (parseMarketingSitePagePath(href)) return isMarketingPageExternal(marketingEnabled)

  return /^https?:\/\//i.test(href.trim())
}

/** Relative in-app path for TanStack Router when the link is internal. */
export function getSiteLinkInternalPath(href: string): string | null {
  const { pathname, hash } = splitHrefHash(href)

  const docsPath = parseDocsPagePath(pathname)
  if (docsPath) return appendHrefHash(docsPath, hash)

  const blogPath = parseBlogPagePath(pathname)
  if (blogPath) return appendHrefHash(blogPath, hash)

  const productPath = parseProductPagePath(pathname)
  if (productPath) return appendHrefHash(productPath, hash)

  const marketingPath = parseMarketingSitePagePath(pathname)
  if (marketingPath) return appendHrefHash(marketingPath, hash)

  if (pathname.startsWith('/')) {
    return appendHrefHash(pathname.split('?')[0] ?? pathname, hash)
  }

  return null
}
