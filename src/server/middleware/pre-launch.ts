import { createMiddleware } from '@tanstack/react-start'
import {
  isPreLaunchAllowedPath,
  isPreLaunchDocumentRequest,
  isPreLaunchHeavyContentPath,
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

function preLaunchBlockedResponse(): Response {
  return new Response('Service temporarily unavailable', {
    status: 503,
    headers: {
      'Content-Type': 'text/plain; charset=utf-8',
      'Cache-Control': 'no-store',
      'Retry-After': '300',
    },
  })
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

  if (isPreLaunchHeavyContentPath(path)) {
    throw preLaunchBlockedResponse()
  }

  if (!isPreLaunchDocumentRequest(request)) {
    return next()
  }

  throw Response.redirect(new URL('/init', request.url), 302)
})
