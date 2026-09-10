import { createMiddleware } from '@tanstack/react-start'
import {
  isRootRedirectPath,
  resolveRootGuestRedirect,
  rootRedirectLocationPath,
} from '@/lib/root-guest-redirect'
import { applyNoIndexResponseHeaders } from '@/lib/seo/indexing'

/**
 * SSR redirect for `/` on production hosts: guests 301 to `/home` (or /init /
 * sign-in). A console session cookie 302s to `/app`, which calls `account.get`
 * on the client (HttpOnly cookies are not visible to JS). Localhost skips this
 * and uses the client loader in `routes/_public/index.tsx` (`cookieFallback`).
 *
 * `/home` uses 301 so crawlers index the marketing homepage. Pre-launch `/init`
 * stays 302. Any HTML that still renders `/` is noindexed.
 */
export const rootGuestRedirectMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  const redirect = resolveRootGuestRedirect(request, pathname)
  if (redirect) {
    const headers = new Headers({
      Location: rootRedirectLocationPath(redirect.url),
      Vary: 'Cookie',
      'Cache-Control': 'private, no-store',
    })
    throw new Response(null, { status: redirect.status, headers })
  }

  const result = await next()
  if (!isRootRedirectPath(pathname) || !result.response) {
    return result
  }

  const { response } = result
  return {
    ...result,
    response: new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers: applyNoIndexResponseHeaders(response.headers),
    }),
  }
})
