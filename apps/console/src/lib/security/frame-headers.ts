/**
 * Pages that must never render inside another site's frame: every screen of
 * the `_auth` layout (sign-in, sign-up, MFA, recovery, verification, invites,
 * OAuth2 consent and device approval). A framed consent screen lets a hidden
 * overlay supply the one click that approves an attacker's OAuth2 client.
 *
 * The rest of the console is left alone until any embedding of it is known.
 */
const AUTH_SCREEN_PATHS = new Set([
  '/sign-in',
  '/sign-up',
  '/sign-out',
  '/mfa',
  '/recovery',
  '/verify-email',
  '/join',
  '/education/join',
  '/oauth2/consent',
  '/oauth2/device',
  '/auth/magic-url',
  '/auth/oauth2/success',
  '/auth/oauth2/failure',
  '/auth/alerts/unsubscribe',
  '/agent/mcp/callback',
  '/assistant/mcp/callback',
])

const FRAME_ANCESTORS_NONE = "frame-ancestors 'none'"

/** Whether a request path is one of the auth screens, with or without the `/console` prefix. */
export function isAuthScreenPath(pathname: string): boolean {
  let path = pathname.replace(/\/+$/, '') || '/'
  if (path === '/console' || path.startsWith('/console/')) {
    path = path.slice('/console'.length) || '/'
  }
  return AUTH_SCREEN_PATHS.has(path.toLowerCase())
}

/**
 * Refuses framing of an auth screen: `X-Frame-Options: DENY` for older browsers,
 * and `frame-ancestors 'none'` in the Content-Security-Policy. Browsers obey
 * `frame-ancestors` over `X-Frame-Options`, so a directive already in the policy
 * is replaced, whatever it allowed; every other directive is kept.
 */
export function applyFrameDenyHeaders(
  headers: Headers,
  pathname: string,
): Headers {
  if (!isAuthScreenPath(pathname)) return headers

  headers.set('X-Frame-Options', 'DENY')

  const directives = (headers.get('Content-Security-Policy') ?? '')
    .split(';')
    .map((directive) => directive.trim())
    .filter(
      (directive) =>
        directive !== '' && !/^frame-ancestors(\s|$)/i.test(directive),
    )
  headers.set(
    'Content-Security-Policy',
    [...directives, FRAME_ANCESTORS_NONE].join('; '),
  )

  return headers
}
