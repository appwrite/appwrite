/**
 * Shared by `post-auth-navigation` and `console-impersonation`, which must not
 * import each other, so the console keeps one definition of a safe redirect.
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
