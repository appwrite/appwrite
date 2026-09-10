import { createMiddleware } from '@tanstack/react-start'
import {
  isLegacyConsolePath,
  rewriteLegacyConsolePath,
} from '@/lib/legacy-console-path'

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

/**
 * Pre-2.0 console URLs (`/console`, `/console/...`, typed resource segments).
 * Exact `/console` is not a live route (Vite SSR 404s it); it 302s to `/`.
 */
export const legacyConsolePathMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  if (process.env.TSS_PRERENDERING === 'true') {
    return next()
  }

  const path = resolvePathname(pathname, request.url)
  if (!isLegacyConsolePath(path)) {
    return next()
  }

  const url = new URL(request.url)
  const location = rewriteLegacyConsolePath(url.pathname) + url.search
  const current = url.pathname + url.search
  if (location === current) {
    return next()
  }

  throw Response.redirect(new URL(location, url.origin), 302)
})
