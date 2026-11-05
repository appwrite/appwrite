/**
 * Guard for `redirect` search params before they reach the router or
 * `window.location`. A leading-slash check alone is not enough: the URL parser
 * reads a backslash as a slash in http(s) contexts and strips ASCII tab, CR and
 * LF before parsing, so `/\evil.example` and `/<TAB>/evil.example` both
 * resolve to another origin. Callers must retain the original bytes rather than
 * the normalized URL, since query values may legitimately carry URLs of their
 * own.
 */
export function isValidRelativeRedirect(value: string): boolean {
  if (
    !value.startsWith('/') ||
    value.startsWith('//') ||
    /[\\\u0000-\u001f\u007f]/.test(value)
  ) {
    return false
  }
  try {
    // Validate destination structure, not URLs embedded in query values.
    const origin = 'https://console.invalid'
    const destination = new URL(value, origin)
    const pathname = decodeURIComponent(destination.pathname)
    return (
      destination.origin === origin &&
      !pathname.startsWith('//') &&
      !/[\\\u0000-\u001f\u007f]/.test(pathname)
    )
  } catch {
    return false
  }
}
