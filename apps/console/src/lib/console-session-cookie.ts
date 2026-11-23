export const CONSOLE_SESSION_COOKIE_NAME = 'a_session_console'

const CONSOLE_SESSION_COOKIE_PATTERN = new RegExp(
  `(?:^|;\\s*)${CONSOLE_SESSION_COOKIE_NAME}=([^;]*)`,
)

/** True when the raw Cookie header includes a non-empty console session value. */
export function hasConsoleSessionCookieFromHeader(
  cookieHeader: string | null | undefined,
): boolean {
  if (!cookieHeader) return false
  const match = cookieHeader.match(CONSOLE_SESSION_COOKIE_PATTERN)
  return Boolean(match?.[1]?.trim())
}
