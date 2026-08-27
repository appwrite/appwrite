import { createMiddleware } from '@tanstack/react-start'
import {
  isPreLaunchAllowedPath,
  isPreLaunchDocumentRequest,
  isPreLaunchModeEnabled,
} from '@/lib/pre-launch'

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

/** Send every locked document navigation to `/init` while pre-launch is on. */
export const preLaunchMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  if (process.env.TSS_PRERENDERING === 'true') {
    return next()
  }

  if (!isPreLaunchModeEnabled(request.headers.get('cookie'))) {
    return next()
  }

  const path = resolvePathname(pathname, request.url)
  if (isPreLaunchAllowedPath(path)) {
    return next()
  }

  if (!isPreLaunchDocumentRequest(request)) {
    return next()
  }

  throw Response.redirect(new URL('/init', request.url), 302)
})
