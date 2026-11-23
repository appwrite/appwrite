/**
 * W3C well-known URL for changing passwords.
 *
 * @see https://www.w3.org/TR/change-password-url/
 * @see https://web.dev/articles/change-password-url
 */

/** Reserved discovery path. Must redirect, not host the form itself. */
export const CHANGE_PASSWORD_WELL_KNOWN_PATH = '/.well-known/change-password'

/** Console page (and card hash) where the user updates their password. */
export const CHANGE_PASSWORD_PAGE_PATH = '/account/security#card-password'

/**
 * Chrome (and other clients) fetch this reserved path to check that missing
 * resources return a non-200 status. A 200 here makes them ignore
 * `/.well-known/change-password`.
 *
 * @see https://wicg.github.io/change-password-url/response-code-reliability.html
 */
export const HTTP_STATUS_RELIABILITY_WELL_KNOWN_PATH =
  '/.well-known/resource-that-should-not-exist-whose-status-code-should-not-be-200'

export function normalizeWellKnownPath(pathname: string): string {
  return pathname.replace(/\/+$/, '') || '/'
}

function pathnameFromRequest(
  request: Request,
  pathname?: string,
): string {
  if (pathname) return normalizeWellKnownPath(pathname)
  try {
    return normalizeWellKnownPath(new URL(request.url).pathname)
  } catch {
    return '/'
  }
}

/**
 * HTTP response for the change-password well-known URL and Chrome's
 * status-code reliability probe. Returns null for unrelated paths.
 */
export function wellKnownChangePasswordResponse(
  request: Request,
  pathname?: string,
): Response | null {
  const path = pathnameFromRequest(request, pathname)

  if (path === CHANGE_PASSWORD_WELL_KNOWN_PATH) {
    const location = new URL(CHANGE_PASSWORD_PAGE_PATH, request.url).toString()
    return new Response(null, {
      status: 302,
      headers: {
        Location: location,
        'Cache-Control': 'no-store',
      },
    })
  }

  if (path === HTTP_STATUS_RELIABILITY_WELL_KNOWN_PATH) {
    return new Response('Not Found', {
      status: 404,
      headers: {
        'Content-Type': 'text/plain; charset=utf-8',
        'Cache-Control': 'no-store',
      },
    })
  }

  return null
}
