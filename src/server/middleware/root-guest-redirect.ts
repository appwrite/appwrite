import { createMiddleware } from '@tanstack/react-start'
import { hasConsoleSessionCookieFromHeader } from '@/lib/console-session-cookie'

function isRootPath(pathname: string): boolean {
  return pathname === '/' || pathname === ''
}

/**
 * SSR redirect for `/`: guests without a console session cookie go to `/home`
 * before the app shell is rendered. Logged-in users keep the existing client
 * redirect flow in `routes/_public/index.tsx`.
 */
export const rootGuestRedirectMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  if (!isRootPath(pathname)) {
    return next()
  }

  if (
    hasConsoleSessionCookieFromHeader(request.headers.get('cookie'))
  ) {
    return next()
  }

  throw Response.redirect(new URL('/home', request.url), 302)
})
