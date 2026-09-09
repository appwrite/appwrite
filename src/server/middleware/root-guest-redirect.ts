import { createMiddleware } from '@tanstack/react-start'
import { resolveRootGuestRedirectUrl } from '@/lib/root-guest-redirect'

/**
 * SSR redirect for `/` on production hosts: guests without a console session
 * cookie go to the marketing home (or /init / sign-in per profile) before the
 * SPA shell loads. Localhost skips this middleware path and uses the client
 * loader in `routes/_public/index.tsx` instead (`cookieFallback` sessions).
 */
export const rootGuestRedirectMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  const redirectUrl = resolveRootGuestRedirectUrl(request, pathname)
  if (redirectUrl) {
    throw Response.redirect(redirectUrl, 302)
  }
  return next()
})
