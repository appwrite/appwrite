import { getActiveProfileFeatures } from '@/lib/console-profiles'

const REVIEWS_DOCS_PATH = '/docs/products/reviews'

export function isReviewsDocsSlug(slug: string): boolean {
  return slug === 'products/reviews' || slug.startsWith('products/reviews/')
}

export function isReviewsDocsPathname(pathname: string): boolean {
  return (
    pathname === REVIEWS_DOCS_PATH ||
    pathname.startsWith(`${REVIEWS_DOCS_PATH}/`)
  )
}

export function isReviewsDocsHref(href: string): boolean {
  const path = href.split('#')[0].split('?')[0]
  return isReviewsDocsPathname(path)
}

/** Reviews docs follow the `reviews` flag, which the quality team turns on from the header Previews menu. */
export function isReviewsDocsEnabled(): boolean {
  return getActiveProfileFeatures().reviews
}

/**
 * Whether reviews docs should be treated as unavailable for route guards.
 *
 * The `reviews` flag defaults to off and is turned on through a debug profile
 * override, which lives in localStorage and isn't visible to SSR. On the
 * server, do not block so a reload doesn't bounce to /docs before the client
 * can apply the override. The docs sidebar still enforces the real flag.
 */
export function shouldBlockReviewsDocs(): boolean {
  if (isReviewsDocsEnabled()) return false
  if (typeof window === 'undefined') return false
  return true
}
