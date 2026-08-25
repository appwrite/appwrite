import { createMiddleware } from '@tanstack/react-start'
import { getRuntimeConfig } from '@/lib/runtime-config'
import {
  hasWebsiteAccessCookieFromHeader,
  isWebsiteAccessEnabled,
  isWebsiteAccessProtectedPath,
} from '@/lib/website-access'

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

/** Soft-launch password gate for all pages. */
export const websiteAccessMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  // Prerender fetches must reach the page HTML; a 302 fails the build.
  if (process.env.TSS_PRERENDERING === 'true') {
    return next()
  }

  if (!isWebsiteAccessEnabled(getRuntimeConfig().websiteAccess)) {
    return next()
  }

  const path = resolvePathname(pathname, request.url)

  if (!isWebsiteAccessProtectedPath(path)) {
    return next()
  }

  if (hasWebsiteAccessCookieFromHeader(request.headers.get('cookie'))) {
    return next()
  }

  const redirectUrl = new URL('/access', request.url)
  const redirectTarget = `${path}${new URL(request.url).search}`
  if (redirectTarget && redirectTarget !== '/access') {
    redirectUrl.searchParams.set('redirect', redirectTarget)
  }

  throw Response.redirect(redirectUrl, 302)
})
