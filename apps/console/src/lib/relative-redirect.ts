/**
 * Same-origin redirect targets read from URLs, storage or API responses.
 *
 * A value passes only when it is a `/`-rooted path that the URL parser keeps
 * on the current origin. The parser treats `\` as `/` and strips ASCII tab,
 * newline and carriage return before parsing, so `/\host` and `/<TAB>/host`
 * both resolve to `//host`; those are rejected before and after parsing
 * against a fixed origin.
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
