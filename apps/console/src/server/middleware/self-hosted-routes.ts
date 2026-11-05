import { createMiddleware } from '@tanstack/react-start'
import { getEnvProfileId } from '@/lib/console-profiles'
import { isPreLaunchDocumentRequest } from '@/lib/pre-launch'
import { isSelfHostedAllowedPath } from '@/lib/self-hosted-route-access'

/**
 * When `VITE_CONSOLE_PROFILE=self-hosted`, document loads outside the console
 * and auth go to sign-in before any page renders. Debug-menu overrides are
 * client-only and are handled after hydration.
 */
export const selfHostedRoutesMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  if (process.env.TSS_PRERENDERING === 'true') {
    return next()
  }
  if (getEnvProfileId() !== 'self-hosted') {
    return next()
  }

  const path = pathname ?? new URL(request.url).pathname
  if (isSelfHostedAllowedPath(path)) {
    return next()
  }
  if (!isPreLaunchDocumentRequest(request)) {
    return next()
  }

  throw Response.redirect(new URL('/sign-in', request.url), 302)
})
