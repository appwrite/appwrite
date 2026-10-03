import { createMiddleware } from '@tanstack/react-start'
import { getProfileFeaturesForCookieHeader } from '@/lib/console-profiles'
import { matchesMarketingPagePath } from '@/lib/marketing/is-marketing-page-path'
import { isPreLaunchDocumentRequest } from '@/lib/pre-launch'
import { isRootRedirectPath } from '@/lib/root-guest-redirect'

/**
 * Send marketing and docs document requests to `/` when the `marketing`
 * feature is off (self-hosted), before any marketing HTML renders. `/` is
 * owned by rootGuestRedirectMiddleware, which routes to sign-in or the console.
 */
export const marketingDisabledMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  if (process.env.TSS_PRERENDERING === 'true') return next()
  const path = pathname || new URL(request.url).pathname
  if (isRootRedirectPath(path)) return next()
  if (!matchesMarketingPagePath(path)) return next()
  if (!isPreLaunchDocumentRequest(request)) return next()
  if (getProfileFeaturesForCookieHeader(request.headers.get('cookie')).marketing) {
    return next()
  }

  throw new Response(null, {
    status: 302,
    headers: {
      Location: '/',
      Vary: 'Cookie',
      'Cache-Control': 'private, no-store',
    },
  })
})
