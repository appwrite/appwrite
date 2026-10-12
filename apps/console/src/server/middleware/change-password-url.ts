import { createMiddleware } from '@tanstack/react-start'
import { wellKnownChangePasswordResponse } from '@/lib/seo/change-password-url'

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
 * Redirect `/.well-known/change-password` to the account password form, and
 * 404 Chrome's status-code reliability probe so password managers trust the
 * well-known URL.
 */
export const changePasswordUrlMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  const response = wellKnownChangePasswordResponse(
    request,
    resolvePathname(pathname, request.url),
  )
  if (response) {
    throw response
  }
  return next()
})
