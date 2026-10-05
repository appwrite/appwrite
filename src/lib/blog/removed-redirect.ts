import { REMOVED_BLOG_POST_SLUGS } from './generated/removed'

const BLOG_POST_PATH_PATTERN = /^\/blog\/post\/([^/]+?)(?:\.md)?\/*$/

export const REMOVED_BLOG_POST_REDIRECT_TARGET = '/home'

/**
 * Returns the redirect target for a post marked `removed: true` (or its `.md`
 * export), or null for any other path.
 */
export function getRemovedBlogPostRedirectTarget(pathname: string): string | null {
  const slug = pathname.match(BLOG_POST_PATH_PATTERN)?.[1]
  if (!slug || !REMOVED_BLOG_POST_SLUGS.has(slug)) return null
  return REMOVED_BLOG_POST_REDIRECT_TARGET
}
