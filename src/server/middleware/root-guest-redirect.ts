import { createMiddleware } from '@tanstack/react-start'
import {
  isRootRedirectPath,
  resolveRootGuestRedirect,
} from '@/lib/root-guest-redirect'
import { applyNoIndexResponseHeaders } from '@/lib/seo/indexing'

/**
 * SSR redirect for `/` on production hosts: guests without a console session
 * cookie go to the marketing home (or /init / sign-in per profile) before the
 * SPA shell loads. Localhost skips this middleware path and uses the client
 * loader in `routes/_public/index.tsx` instead (`cookieFallback` sessions).
 *
 * `/home` uses 301 so crawlers index the marketing homepage. Pre-launch `/init`
 * stays 302. Any HTML that still renders `/` is noindexed.
 */
export const rootGuestRedirectMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  const redirect = resolveRootGuestRedirect(request, pathname)
  if (redirect) {
    throw Response.redirect(redirect.url, redirect.status)
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
