import { createMiddleware } from '@tanstack/react-start'
import { getRemovedBlogPostRedirectTarget } from '@/lib/blog/removed-redirect'
import { getLegacyRedirectTarget } from '@/lib/seo/legacy-redirects'

function resolvePathname(
  pathname: string | undefined,
  requestUrl: string,
): string {
  if (pathname) return pathname
  try {
    return new URL(requestUrl).pathname
  } catch {
    return '/'
  }
}

/**
 * Permanent (301) redirects for legacy URLs ported from the old website so
 * inbound links and search engine results keep resolving, and for blog posts
 * marked `removed: true` (sent to `/home`). The incoming query string is
 * preserved on the target URL.
 */
export const legacyRedirectsMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  // Prerender fetches must reach the page HTML; a redirect fails the build.
  if (process.env.TSS_PRERENDERING === 'true') {
    return next()
  }

  const path = resolvePathname(pathname, request.url)
  const target =
    getLegacyRedirectTarget(path) ?? getRemovedBlogPostRedirectTarget(path)
  if (!target) {
    return next()
  }

  const targetUrl = new URL(target, request.url)
  new URL(request.url).searchParams.forEach((value, key) => {
    if (!targetUrl.searchParams.has(key)) {
      targetUrl.searchParams.append(key, value)
    }
  })

  // Relative Location: behind the TLS-terminating proxy `request.url` is
  // http://, so an absolute target would add an http hop to every redirect.
  throw new Response(null, {
    status: 301,
    headers: {
      Location: `${targetUrl.pathname}${targetUrl.search}${targetUrl.hash}`,
    },
  })
})
