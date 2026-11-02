/**
 * Shared guard for a `redirect` search param before it reaches the router or
 * `window.location`. It lives apart from the flows that consume it (post-auth
 * navigation, operator impersonation) so each one validates the same way
 * without pulling in the others' dependencies.
 *
 * In an http(s) base context the URL parser treats a backslash as a slash and
 * strips ASCII tab, LF and CR before parsing, so `/\host` and `/<TAB>/host`
 * both resolve to another origin. Leading-slash checks alone never see that -
 * only the parsed origin does.
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
    // Callers must retain the original bytes rather than the normalized URL.
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
