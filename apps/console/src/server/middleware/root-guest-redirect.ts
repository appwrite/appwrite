import { createMiddleware } from '@tanstack/react-start'
import {
  isRootConsoleHopRequest,
  isRootRedirectPath,
  resolveRootGuestRedirect,
  rootRedirectLocationPath,
} from '@/lib/root-guest-redirect'
import { applyNoIndexResponseHeaders } from '@/lib/seo/indexing'

/**
 * SSR handling for `/` on production hosts: guests get the server-rendered
 * marketing homepage (or a redirect to /init / sign-in). A console session
 * cookie keeps `/` as a client-only hop, where the client calls `account.get`
 * (HttpOnly cookies are not visible to JS). Localhost always uses the client
 * hop in `routes/_marketing/index.tsx` (`cookieFallback`).
 *
 * The response varies by cookie, and the console hop HTML is noindexed.
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
  const headers = isRootConsoleHopRequest(request)
    ? applyNoIndexResponseHeaders(response.headers)
    : new Headers(response.headers)
  headers.append('Vary', 'Cookie')
  return {
    ...result,
    response: new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers,
    }),
  }
})
